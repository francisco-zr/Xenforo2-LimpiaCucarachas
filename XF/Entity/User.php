<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\XF\Entity;

class User extends XFCP_User
{
	public function isSpamCheckRequired()
	{
		if (parent::isSpamCheckRequired())
		{
			return true;
		}

		$options = $this->app()->options();
		if (empty($options->foroplLcEnabled) || trim($options->foroplLcApiKey ?? '') === '')
		{
			return false;
		}

		if ($this->is_admin || $this->is_moderator)
		{
			return false;
		}

		if ($this->hasPermission('general', 'bypassSpamCheck'))
		{
			return false;
		}

		$maxAgeDays = (int) $options->foroplLcMaxAccountAgeDays;

		return $this->register_date >= (\XF::$time - $maxAgeDays * 86400);
	}
}
