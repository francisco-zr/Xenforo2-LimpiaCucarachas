<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Cron;

class CleanUp
{
	public static function cleanUpLog(): void
	{
		$retentionDays = (int) \XF::options()->foroplLcLogRetentionDays;
		$cutOff = \XF::$time - $retentionDays * 86400;

		\XF::db()->delete('xf_foropl_lc_log', 'log_date < ?', $cutOff);
	}
}
