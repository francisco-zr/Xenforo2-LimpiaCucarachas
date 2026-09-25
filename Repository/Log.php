<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Repository;

use foroPL\LimpiaCucarachas\Entity\Log as LogEntity;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;

class Log extends Repository
{
	public function logVerdict(
		User $user,
		string $contentType,
		string $verdict,
		string $reason,
		string $message,
		int $responseMs = 0,
		int $contentId = 0
	): void
	{
		try
		{
			$filterer = $this->app()->inputFilterer();
			$reason = $filterer->filter((string) $reason, 'str');
			$message = $filterer->filter((string) $message, 'str');

			/** @var LogEntity $log */
			$log = $this->em->create('foroPL\LimpiaCucarachas:Log');
			$log->user_id = $user->user_id;
			$log->content_type = $contentType;
			$log->content_id = $contentId;
			$log->verdict = $verdict;
			$log->reason = mb_substr($reason, 0, 250, 'utf-8');
			$log->snippet = mb_substr($message, 0, 500, 'utf-8');
			$log->response_ms = $responseMs;
			$log->save();
		}
		catch (\Exception $e)
		{
			\XF::logException($e, false, 'LimpiaCucarachas log write error: ');
		}
	}

	/**
	 * The most recent AI verdict for a piece of content, used to attribute a
	 * moderator action to the AI check it is reacting to. Rows written by a
	 * moderator (feedback) are excluded, as are non-AI veredicts.
	 */
	public function getLatestAiVerdict(string $contentType, int $contentId): ?LogEntity
	{
		if ($contentId <= 0)
		{
			return null;
		}

		/** @var LogEntity|null $log */
		$log = $this->finder('foroPL\LimpiaCucarachas:Log')
			->where('content_type', $contentType)
			->where('content_id', $contentId)
			->where('verdict', ['spam', 'ham', 'error'])
			->order('log_date', 'DESC')
			->order('log_id', 'DESC')
			->fetchOne();

		return $log;
	}

	/**
	 * Records a moderator's decision on content the AI had already judged, so
	 * the log shows whether the verdict was approved or confirmed as spam and
	 * by whom.
	 */
	public function logModeratorFeedback(
		int $userId,
		string $contentType,
		int $contentId,
		string $verdict,
		string $reason,
		int $moderatorUserId
	): void
	{
		try
		{
			$filterer = $this->app()->inputFilterer();

			/** @var LogEntity $log */
			$log = $this->em->create('foroPL\LimpiaCucarachas:Log');
			$log->user_id = $userId;
			$log->content_type = $contentType;
			$log->content_id = $contentId;
			$log->verdict = $verdict;
			$log->reason = mb_substr($filterer->filter((string) $reason, 'str'), 0, 250, 'utf-8');
			$log->moderator_user_id = $moderatorUserId;
			$log->save();
		}
		catch (\Exception $e)
		{
			\XF::logException($e, false, 'LimpiaCucarachas log write error: ');
		}
	}
}
