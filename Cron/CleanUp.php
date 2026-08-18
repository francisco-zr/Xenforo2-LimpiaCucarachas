<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Cron;

use foroPL\LimpiaCucarachas\Repository\Queue as QueueRepository;

class CleanUp
{
	public static function cleanUpLog(): void
	{
		$retentionDays = (int) \XF::options()->foroplLcLogRetentionDays;
		$cutOff = \XF::$time - $retentionDays * 86400;

		\XF::db()->delete('xf_foropl_lc_log', 'log_date < ?', $cutOff);
	}

	public static function cleanUpReservations(): void
	{
		/** @var QueueRepository $queueRepo */
		$queueRepo = \XF::repository(QueueRepository::class);
		$queueRepo->cleanUpExpired(1000);
	}
}
