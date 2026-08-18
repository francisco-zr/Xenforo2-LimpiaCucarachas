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
			$reservation = $queueRepo->reserveQueueSlot(
				$user,
				$contentType,
				(int) $options->foroplLcMaxQueuedMessages,
				(int) ($extraParams['content_id'] ?? 0)
			);

			if (!$reservation['reserved'])
			{
				$pendingCount = $reservation['queued_count'] + $reservation['reserved_count'];
				$this->rememberDecision($checkKey, 'denied');
				$this->logDetail('foropl_lc_queue_flood', ['count' => $pendingCount]);
				$this->writeLog(
					$user,
					$contentType,
					'denied_flood',
					"$pendingCount messages queued or being checked",
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

	public function submitSpam($contentType, $contentIds)
	{
	}

	public function submitHam($contentType, $contentIds)
	{
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
		if (!preg_match_all('#(?:https?://|www\.)[^\s\[\]<>"\'\)]+#i', $message, $matches))
		{
			return false;
		}

		$whitelist = $this->getWhitelistedDomains();

		foreach ($matches[0] AS $url)
		{
			if (stripos($url, 'http') !== 0)
			{
				$url = 'http://' . $url;
			}

			$host = parse_url($url, PHP_URL_HOST);
			if (!$host || !$this->isWhitelistedHost(strtolower($host), $whitelist))
			{
				return true;
			}
		}

		return false;
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
