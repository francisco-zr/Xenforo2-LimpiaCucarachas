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
		int $responseMs = 0
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
