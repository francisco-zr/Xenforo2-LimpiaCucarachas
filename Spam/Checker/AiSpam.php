<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Spam\Checker;

use foroPL\LimpiaCucarachas\Entity\Log;
use GuzzleHttp\Exception\TransferException;
use XF\Entity\User;
use XF\Spam\Checker\AbstractProvider;
use XF\Spam\Checker\ContentCheckerInterface;

class AiSpam extends AbstractProvider implements ContentCheckerInterface
{
	protected function getType()
	{
		return 'LimpiaCucarachasAi';
	}

	public function check(User $user, $message, array $extraParams = [])
	{
		if (!$this->isRecentAccount($user))
		{
			$this->logDecision('allowed');
			return;
		}

		$contentType = $extraParams['content_type'] ?? '';

		$queuedCount = $this->getModeratedContentCount($user);
		if ($queuedCount >= $this->app()->options()->foroplLcMaxQueuedMessages)
		{
			$this->logDecision('denied');
			$this->logDetail('foropl_lc_queue_flood', ['count' => $queuedCount]);
			$this->writeLog($user, $contentType, 'denied_flood', "$queuedCount messages already queued", $message);
			return;
		}

		if ($this->app()->options()->foroplLcRequireLink && !$this->hasSuspiciousLink($message))
		{
			$this->logDecision('allowed');
			return;
		}

		$startTime = microtime(true);

		try
		{
			$verdict = $this->queryAi($user, $message, $extraParams);
			$responseMs = (int) round((microtime(true) - $startTime) * 1000);

			if ($verdict['spam'])
			{
				$this->logDecision('moderated');
				$this->logDetail('foropl_lc_ai_detected_spam', ['reason' => $verdict['reason']]);
			}
			else
			{
				$this->logDecision('allowed');
			}

			$this->logParam('foroplLcAi', $verdict);
			$this->writeLog($user, $contentType, $verdict['spam'] ? 'spam' : 'ham', $verdict['reason'], $message, $responseMs);
		}
		catch (TransferException | \RuntimeException | \InvalidArgumentException $e)
		{
			$responseMs = (int) round((microtime(true) - $startTime) * 1000);

			$this->logDecision('moderated');
			$this->logDetail('foropl_lc_ai_check_failed');
			$this->writeLog($user, $contentType, 'error', $e->getMessage(), $message, $responseMs);
		}
	}

	public function submitSpam($contentType, $contentIds)
	{
	}

	public function submitHam($contentType, $contentIds)
	{
	}

	protected function isRecentAccount(User $user): bool
	{
		$maxAgeDays = (int) $this->app()->options()->foroplLcMaxAccountAgeDays;

		return $user->register_date >= (\XF::$time - $maxAgeDays * 86400);
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

	protected function getModeratedContentCount(User $user): int
	{
		$db = $this->app()->db();

		$posts = (int) $db->fetchOne(
			"SELECT COUNT(*) FROM xf_post WHERE user_id = ? AND message_state = 'moderated'",
			$user->user_id
		);
		$threads = (int) $db->fetchOne(
			"SELECT COUNT(*) FROM xf_thread WHERE user_id = ? AND discussion_state = 'moderated'",
			$user->user_id
		);

		return $posts + $threads;
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

	protected function writeLog(User $user, string $contentType, string $verdict, string $reason, string $message, int $responseMs = 0): void
	{
		try
		{
			/** @var Log $log */
			$log = $this->app()->em()->create('foroPL\LimpiaCucarachas:Log');
			$log->user_id = $user->user_id;
			$log->content_type = $contentType;
			$log->verdict = $verdict;
			$log->reason = mb_substr($reason, 0, 250, 'utf-8');
			$log->snippet = mb_substr($message, 0, 500, 'utf-8');
			$log->response_ms = $responseMs;
			$log->save();
		}
		catch (\Exception $e)
		{
			$this->app()->logException($e, false, 'LimpiaCucarachas log write error: ');
		}
	}
}
