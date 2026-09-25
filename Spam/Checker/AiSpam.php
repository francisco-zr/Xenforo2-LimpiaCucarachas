<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Spam\Checker;

use foroPL\LimpiaCucarachas\Repository\Log as LogRepository;
use foroPL\LimpiaCucarachas\Repository\Queue as QueueRepository;
use GuzzleHttp\Exception\TransferException;
use XF\Entity\User;
use XF\Spam\Checker\AbstractProvider;
use XF\Spam\Checker\ContentCheckerInterface;

class AiSpam extends AbstractProvider implements ContentCheckerInterface
{
	protected $requestDecisions = [];

	protected function getType()
	{
		return 'LimpiaCucarachasAi';
	}

	public function check(User $user, $message, array $extraParams = [])
	{
		/** @var \foroPL\LimpiaCucarachas\XF\Entity\User $user */
		$message = (string) $message;
		$contentType = (string) ($extraParams['content_type'] ?? '');
		$options = $this->app()->options();
		$checkKey = $this->getRequestCheckKey($contentType, $extraParams, $message);

		if ($checkKey !== null && isset($this->requestDecisions[$checkKey]))
		{
			$this->logDecision($this->requestDecisions[$checkKey]);
			return;
		}

		// The contact form has no approval queue: a 'moderated' decision
		// silently discards the email, so the AI must never judge it.
		if ($contentType === 'contact' || !$user->user_id)
		{
			$this->rememberDecision($checkKey, 'allowed');
			return;
		}

		// Exempt groups are skipped unconditionally, including a first post
		// that would otherwise be checked before the watch window applies.
		if ($user->foroplLcIsBypassed())
		{
			$this->rememberDecision($checkKey, 'allowed');
			return;
		}

		if (!$user->foropl_lc_first_post_date)
		{
			$user->fastUpdate('foropl_lc_first_post_date', \XF::$time);
		}
		elseif (!$user->foroplLcIsWatched())
		{
			$this->rememberDecision($checkKey, 'allowed');
			return;
		}

		if ($this->isQueueableContentType($contentType))
		{
			/** @var QueueRepository $queueRepo */
			$queueRepo = $this->app()->repository(QueueRepository::class);
			$queuedCount = $queueRepo->getModeratedContentCount((int) $user->user_id);

			if ($queuedCount >= max(1, (int) $options->foroplLcMaxQueuedMessages))
			{
				$this->rememberDecision($checkKey, 'denied');
				$this->logDetail('foropl_lc_queue_flood', ['count' => $queuedCount]);
				$this->writeLog(
					$user,
					$contentType,
					'denied_flood',
					"$queuedCount messages queued or being checked",
					$message
				);
				return;
			}
		}

		if ($options->foroplLcRequireLink && !$this->hasSuspiciousLink($message))
		{
			$this->rememberDecision($checkKey, 'allowed');
			return;
		}

		$startTime = microtime(true);

		try
		{
			$verdict = $this->queryAi($user, $message, $extraParams);
			$responseMs = (int) round((microtime(true) - $startTime) * 1000);

			if ($verdict['spam'])
			{
				$this->rememberDecision($checkKey, 'moderated');
				$this->logDetail('foropl_lc_ai_detected_spam', [
					'reason' => $verdict['reason'],
					'flagged' => $this->getFlaggedExcerpt($message),
				]);
			}
			else
			{
				$this->rememberDecision($checkKey, 'allowed');
			}

			$this->logParam('foroplLcAi', $verdict);
			$this->writeLog($user, $contentType, $verdict['spam'] ? 'spam' : 'ham', $verdict['reason'], $message, $responseMs);
		}
		catch (TransferException | \RuntimeException | \InvalidArgumentException $e)
		{
			$responseMs = (int) round((microtime(true) - $startTime) * 1000);

			$this->rememberDecision($checkKey, 'moderated');
			$this->logDetail('foropl_lc_ai_check_failed', [
				'flagged' => $this->getFlaggedExcerpt($message),
			]);
			$this->writeLog($user, $contentType, 'error', $e->getMessage(), $message, $responseMs);
		}
	}

	/**
	 * A moderator is confirming content as spam (usually via the spam
	 * cleaner). If the AI had already judged that content, record whether the
	 * human agreed with it or caught a miss.
	 */
	public function submitSpam($contentType, $contentIds)
	{
		$this->logModeratorFeedback((string) $contentType, $contentIds, 'confirmed_spam');
	}

	/**
	 * Content the AI flagged as spam has been approved. Only approvals that
	 * overturn an AI spam flag are recorded, so the log shows false positives
	 * rather than every routine moderation approval.
	 */
	public function submitHam($contentType, $contentIds)
	{
		$this->logModeratorFeedback((string) $contentType, $contentIds, 'approved');
	}

	protected function logModeratorFeedback(string $contentType, $contentIds, string $verdict): void
	{
		$contentIds = $this->normalizeContentIds($contentIds);
		if (!$contentIds)
		{
			return;
		}

		// The AI check runs before the content exists, so the add-on's own log
		// rows have no content ID. XF stores the real ID in the spam check
		// cache and the spam trigger log, which is what ties a moderator action
		// back to the AI verdict for that content.
		$cacheParams = [];
		foreach ($this->getContentSpamCheckParams($contentType, $contentIds) AS $id => $params)
		{
			$cacheParams[(int) $id] = $params;
		}

		$moderatorId = (int) \XF::visitor()->user_id;
		/** @var \foroPL\LimpiaCucarachas\Repository\Log $logRepo */
		$logRepo = $this->app()->repository('foroPL\LimpiaCucarachas:Log');

		foreach ($contentIds AS $contentId)
		{
			$aiVerdict = $this->getAiVerdictForContent($contentType, $contentId, $cacheParams[$contentId] ?? []);
			if ($aiVerdict === null)
			{
				// The AI never judged this content, so there is no verdict for
				// a moderator to confirm or overturn.
				continue;
			}

			if ($verdict === 'confirmed_spam')
			{
				$reason = $aiVerdict === 'spam'
					? 'Moderator confirmed spam (AI had flagged it)'
					: 'Moderator confirmed spam (AI had not flagged it)';
			}
			else
			{
				if ($aiVerdict === 'ham')
				{
					// The AI had not flagged this, so approving it is not a
					// reaction to the AI's verdict.
					continue;
				}

				$reason = $aiVerdict === 'spam'
					? 'Moderator approved content over the AI spam flag'
					: 'Moderator approved content after an AI check error';
			}

			$logRepo->logModeratorFeedback(
				$this->getContentAuthorId($contentType, $contentId),
				$contentType,
				$contentId,
				$verdict,
				$reason,
				$moderatorId
			);
		}
	}

	protected function normalizeContentIds($contentIds): array
	{
		if ($contentIds instanceof \Traversable)
		{
			$contentIds = iterator_to_array($contentIds);
		}
		else if (!is_array($contentIds))
		{
			$contentIds = [$contentIds];
		}

		$ids = [];
		foreach ($contentIds AS $contentId)
		{
			$contentId = (int) $contentId;
			if ($contentId > 0)
			{
				$ids[$contentId] = $contentId;
			}
		}

		return array_values($ids);
	}

	/**
	 * Resolves whether the AI judged this content as spam, ham or an error.
	 * Prefers the spam check cache (which stores the actual verdict), falling
	 * back to the spam trigger log (written for every content type the AI
	 * holds).
	 */
	protected function getAiVerdictForContent(string $contentType, int $contentId, array $cacheParams): ?string
	{
		$ai = $cacheParams['foroplLcAi'] ?? null;
		if (is_array($ai) && array_key_exists('spam', $ai))
		{
			return !empty($ai['spam']) ? 'spam' : 'ham';
		}

		$trigger = $this->app()->finder('XF:SpamTriggerLog')
			->where('content_type', $contentType)
			->where('content_id', $contentId)
			->order('log_date', 'DESC')
			->fetchOne();

		$details = $trigger ? (array) $trigger->details_ : [];
		$detail = $details[$this->getType()] ?? null;
		$phrase = is_array($detail) ? ($detail['phrase'] ?? '') : '';

		if ($phrase === 'foropl_lc_ai_detected_spam')
		{
			return 'spam';
		}
		if ($phrase === 'foropl_lc_ai_check_failed')
		{
			return 'error';
		}

		return null;
	}

	protected function getContentAuthorId(string $contentType, int $contentId): int
	{
		try
		{
			$content = $this->app()->findByContentType($contentType, $contentId);
		}
		catch (\Exception $e)
		{
			return 0;
		}

		return $content ? (int) ($content->user_id ?? 0) : 0;
	}

	protected function getRequestCheckKey(string $contentType, array $extraParams, string $message): ?string
	{
		$contentId = (int) ($extraParams['content_id'] ?? 0);
		if (!$contentId)
		{
			return null;
		}

		return $contentType . ':' . $contentId . ':' . sha1($message);
	}

	protected function rememberDecision(?string $checkKey, string $decision): void
	{
		if ($checkKey !== null)
		{
			$this->requestDecisions[$checkKey] = $decision;
		}

		$this->logDecision($decision);
	}

	protected function isQueueableContentType(string $contentType): bool
	{
		return in_array($contentType, [
			'thread',
			'post',
			'profile_post',
			'profile_post_comment',
		], true);
	}

	protected function hasSuspiciousLink(string $message): bool
	{
		$message = $this->normalizeHomoglyphs($message);
		$whitelist = $this->getWhitelistedDomains();

		if (preg_match_all('#(?:https?://|www\.)[^\s\[\]<>"\'\)]+#i', $message, $matches))
		{
			foreach ($matches[0] AS $url)
			{
				if ($this->isSuspiciousUrl($url, $whitelist))
				{
					return true;
				}
			}
		}

		// The [URL] BB code is the editor's insert-link button and a common
		// obfuscation vector: the tag itself can carry a link (attribute) or
		// the link can be hidden inside the tag body with homoglyph characters.
		// Treat any [URL] tag that does not point to a whitelisted host as
		// suspicious, whether or not it carries a detectable link.
		if (preg_match_all('#\[URL(?:="([^"]*)"|=\'([^\']*)\'|)\](.*?)\[/URL\]#is', $message, $matches, PREG_SET_ORDER))
		{
			foreach ($matches AS $match)
			{
				$attribute = trim($match[1] ?? '');
				if ($attribute === '')
				{
					$attribute = trim($match[2] ?? '');
				}
				$body = $match[3] ?? '';

				if ($attribute !== '')
				{
					if (!$this->isWhitelistedUrl($attribute, $whitelist))
					{
						return true;
					}
				}
				elseif (preg_match('#(?:https?://|www\.)[^\s\[\]<>"\'\)]+#i', $body, $urlMatch))
				{
					if ($this->isSuspiciousUrl($urlMatch[0], $whitelist))
					{
						return true;
					}
				}
				else
				{
					return true;
				}
			}
		}

		return false;
	}

	protected function isWhitelistedUrl(string $url, array $whitelist): bool
	{
		if (preg_match('#^(?:https?://|www\.)#i', $url))
		{
			return !$this->isSuspiciousUrl($url, $whitelist);
		}

		$first = $url[0];
		if ($first === '?' || $first === '#' || $first === '/' || $first === ':')
		{
			// A query, fragment, protocol-relative or colon-led reference is
			// not an external link, matching how XF's getValidUrl() rejects them.
			return true;
		}

		// A scheme-less attribute such as [URL=somedomain.net/somepath] is still
		// rendered as an external link: XF's getValidUrl() prepends http://.
		// Only treat it as internal when it cannot have an external host.
		return !$this->isExternalHostPath($url, $whitelist);
	}

	protected function isExternalHostPath(string $url, array $whitelist): bool
	{
		$host = parse_url('http://' . $url, PHP_URL_HOST);
		if (!$host)
		{
			return false;
		}

		$host = strtolower($host);

		if (strpos($host, '.') === false || $host === 'index.php')
		{
			// Relative paths like "threads/title.123/" have no host; XF also
			// rewrites bare "index.php?..." links to the board's own URL.
			return false;
		}

		return !$this->isWhitelistedHost($host, $whitelist);
	}

	protected function isSuspiciousUrl(string $url, array $whitelist): bool
	{
		if (stripos($url, 'http') !== 0)
		{
			$url = 'http://' . $url;
		}

		$host = parse_url($url, PHP_URL_HOST);
		return !$host || !$this->isWhitelistedHost(strtolower($host), $whitelist);
	}

	/**
	 * Replaces Unicode lookalike (homoglyph) characters with their ASCII
	 * equivalents so obfuscated URLs such as "httрs://" (Cyrillic р) or
	 * fullwidth "ｈｔｔｐｓ" are detected like their plain ASCII forms.
	 */
	protected function normalizeHomoglyphs(string $message): string
	{
		static $map = null;

		if ($map === null)
		{
			$map = [
				// Cyrillic
				"\u{0430}" => 'a', "\u{0432}" => 'b', "\u{0435}" => 'e',
				"\u{043E}" => 'o', "\u{0440}" => 'p', "\u{0441}" => 'c',
				"\u{0443}" => 'y', "\u{0445}" => 'x', "\u{0456}" => 'i',
				"\u{0458}" => 'j', "\u{0455}" => 's', "\u{0461}" => 'w',
				"\u{04BB}" => 'h', "\u{049B}" => 'k', "\u{043F}" => 'n',
				"\u{0442}" => 'm',
				// Greek
				"\u{03B1}" => 'a', "\u{03B2}" => 'b', "\u{03B5}" => 'e',
				"\u{03BF}" => 'o', "\u{03C1}" => 'p', "\u{03C3}" => 's',
				"\u{03C4}" => 't', "\u{03C5}" => 'y', "\u{03C6}" => 'f',
				"\u{03C7}" => 'x', "\u{03B9}" => 'i', "\u{03BD}" => 'v',
				"\u{03BA}" => 'k', "\u{03BC}" => 'm',
				// Fullwidth ASCII
				"\u{FF10}" => '0', "\u{FF11}" => '1', "\u{FF12}" => '2',
				"\u{FF13}" => '3', "\u{FF14}" => '4', "\u{FF15}" => '5',
				"\u{FF16}" => '6', "\u{FF17}" => '7', "\u{FF18}" => '8',
				"\u{FF19}" => '9',
				"\u{FF21}" => 'A', "\u{FF22}" => 'B', "\u{FF23}" => 'C',
				"\u{FF24}" => 'D', "\u{FF25}" => 'E', "\u{FF26}" => 'F',
				"\u{FF27}" => 'G', "\u{FF28}" => 'H', "\u{FF29}" => 'I',
				"\u{FF2A}" => 'J', "\u{FF2B}" => 'K', "\u{FF2C}" => 'L',
				"\u{FF2D}" => 'M', "\u{FF2E}" => 'N', "\u{FF2F}" => 'O',
				"\u{FF30}" => 'P', "\u{FF31}" => 'Q', "\u{FF32}" => 'R',
				"\u{FF33}" => 'S', "\u{FF34}" => 'T', "\u{FF35}" => 'U',
				"\u{FF36}" => 'V', "\u{FF37}" => 'W', "\u{FF38}" => 'X',
				"\u{FF39}" => 'Y', "\u{FF3A}" => 'Z',
				"\u{FF41}" => 'a', "\u{FF42}" => 'b', "\u{FF43}" => 'c',
				"\u{FF44}" => 'd', "\u{FF45}" => 'e', "\u{FF46}" => 'f',
				"\u{FF47}" => 'g', "\u{FF48}" => 'h', "\u{FF49}" => 'i',
				"\u{FF4A}" => 'j', "\u{FF4B}" => 'k', "\u{FF4C}" => 'l',
				"\u{FF4D}" => 'm', "\u{FF4E}" => 'n', "\u{FF4F}" => 'o',
				"\u{FF50}" => 'p', "\u{FF51}" => 'q', "\u{FF52}" => 'r',
				"\u{FF53}" => 's', "\u{FF54}" => 't', "\u{FF55}" => 'u',
				"\u{FF56}" => 'v', "\u{FF57}" => 'w', "\u{FF58}" => 'x',
				"\u{FF59}" => 'y', "\u{FF5A}" => 'z',
				"\u{FF0E}" => '.', "\u{FF0F}" => '/', "\u{FF1A}" => ':',
				// Misc lookalikes
				"\u{0131}" => 'i', "\u{2170}" => 'i', "\u{217C}" => 'l',
			];
		}

		return strtr($message, $map);
	}

	protected function getWhitelistedDomains(): array
	{
		$domains = [];

		$boardHost = parse_url($this->app()->options()->boardUrl, PHP_URL_HOST);
		if ($boardHost)
		{
			$domains[] = strtolower($boardHost);
		}

		foreach (preg_split('/\r?\n/', (string) $this->app()->options()->foroplLcDomainWhitelist) AS $line)
		{
			$line = strtolower(trim($line));
			$line = preg_replace('#^https?://|^www\.|/.*$#', '', $line);
			if ($line !== '')
			{
				$domains[] = $line;
			}
		}

		return $domains;
	}

	protected function isWhitelistedHost(string $host, array $whitelist): bool
	{
		foreach ($whitelist AS $domain)
		{
			if ($host === $domain
				|| substr($host, -strlen('.' . $domain)) === '.' . $domain
			)
			{
				return true;
			}
		}

		return false;
	}

	protected function queryAi(User $user, string $message, array $extraParams): array
	{
		$options = $this->app()->options();

		$response = $this->app()->http()->client()->post(trim($options->foroplLcApiUrl), [
			'timeout' => (int) $options->foroplLcTimeout,
			'connect_timeout' => 5,
			'headers' => [
				'Authorization' => 'Bearer ' . trim($options->foroplLcApiKey),
				'User-Agent' => $this->getUserAgent(),
				'x-opencode-session' => $this->getSessionId($user),
			],
			'json' => [
				'model' => trim($options->foroplLcModel),
				'messages' => $this->buildPrompt($user, $message, $extraParams),
				'temperature' => 0,
				'max_tokens' => (int) $options->foroplLcMaxTokens,
				'response_format' => ['type' => 'json_object'],
			],
		]);

		return $this->parseVerdict($response->getBody()->getContents());
	}

	/**
	 * Identifies the add-on as its own client instead of a generic HTTP
	 * library, as required by providers such as OpenCode Go.
	 */
	protected function getUserAgent(): string
	{
		$addOn = $this->app()->addOnManager()->getById('foroPL/LimpiaCucarachas');
		$version = $addOn && $addOn->version_string ? $addOn->version_string : '1.0';

		return 'ForoPL-LimpiaCucarachas/' . $version . ' (+' . $this->app()->options()->boardUrl . ')';
	}

	/**
	 * A stable session ID per watched user, so providers such as OpenCode Go
	 * can optimize routing and prompt caching. Derived deterministically from
	 * the board URL and user ID, and hashed so the raw ID is not exposed.
	 */
	protected function getSessionId(User $user): string
	{
		$seed = $this->app()->options()->boardUrl . '|' . $user->user_id;

		return 'foropl-lc-' . substr(hash('sha256', $seed), 0, 32);
	}

	protected function buildPrompt(User $user, string $message, array $extraParams): array
	{
		$options = $this->app()->options();

		$systemPrompt = trim($options->foroplLcSystemPrompt ?? '');
		if ($systemPrompt === '')
		{
			$systemPrompt = 'You are a spam-detection filter for an internet discussion forum.'
				. ' You will be given a message posted by a recently registered account.'
				. ' Classify it as spam if it is link spam, advertising, SEO spam, a scam,'
				. ' or promotional content irrelevant to a discussion forum.'
				. ' Legitimate posts may contain links; judge intent and context.'
				. ' Respond with ONLY a JSON object, no other text:'
				. ' {"spam": true or false, "reason": "brief explanation, max 200 characters"}';
		}

		$accountAgeDays = (int) floor((\XF::$time - $user->register_date) / 86400);
		$truncated = mb_substr($message, 0, (int) $options->foroplLcMaxChars, 'utf-8');

		$userPrompt = 'Username: ' . $user->username . "\n"
			. 'Account age: ' . $accountAgeDays . " days\n"
			. 'Messages posted: ' . $user->message_count . "\n"
			. 'Content type: ' . ($extraParams['content_type'] ?? 'unknown') . "\n"
			. "Message:\n---\n" . $truncated;

		return [
			['role' => 'system', 'content' => $systemPrompt],
			['role' => 'user', 'content' => $userPrompt],
		];
	}

	protected function parseVerdict(string $responseBody): array
	{
		$decoded = json_decode($responseBody, true);
		$content = $decoded['choices'][0]['message']['content'] ?? null;
		if (!is_string($content))
		{
			throw new \RuntimeException('Unexpected AI response structure: ' . mb_substr($responseBody, 0, 500, 'utf-8'));
		}

		$content = trim($content);
		if ($content === '')
		{
			$finishReason = $decoded['choices'][0]['finish_reason'] ?? 'unknown';
			throw new \RuntimeException("Empty AI response content (finish_reason: $finishReason)");
		}

		$verdict = null;
		if (preg_match('/\{.*\}/s', $content, $match))
		{
			$verdict = json_decode($match[0], true);
		}
		if (!is_array($verdict) || !isset($verdict['spam']))
		{
			throw new \RuntimeException('Unparseable AI verdict: ' . mb_substr($content, 0, 500, 'utf-8'));
		}

		return [
			'spam' => (bool) $verdict['spam'],
			'reason' => mb_substr((string) ($verdict['reason'] ?? ''), 0, 250, 'utf-8'),
		];
	}

	/**
	 * Kept in the spam trigger details so moderators can see what was actually
	 * submitted, even if the author edits the message while it sits in the queue.
	 */
	protected function getFlaggedExcerpt(string $message): string
	{
		$message = $this->app()->stringFormatter()->stripBbCode($message);
		$message = trim(preg_replace('/\s+/u', ' ', $message));
		$excerpt = mb_substr($message, 0, 300, 'utf-8');

		return $excerpt === $message ? $excerpt : $excerpt . '…';
	}

	protected function writeLog(User $user, string $contentType, string $verdict, string $reason, string $message, int $responseMs = 0): void
	{
		/** @var LogRepository $logRepo */
		$logRepo = $this->app()->repository('foroPL\LimpiaCucarachas:Log');
		$logRepo->logVerdict($user, $contentType, $verdict, $reason, $message, $responseMs);
	}
}
