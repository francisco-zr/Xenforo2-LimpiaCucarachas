<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas;

class Listener
{
	public static function spamContentProviders(\XF\SubContainer\Spam $container, \XF\Container $parentContainer, array &$providers): void
	{
		$options = $parentContainer['options'];
		if (!empty($options->foroplLcEnabled) && trim($options->foroplLcApiKey ?? '') !== '')
		{
			$providers[] = 'foroPL\LimpiaCucarachas:AiSpam';
		}
	}
}
