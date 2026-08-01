<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\XF\Entity;

use XF\Mvc\Entity\Structure;

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

		return $this->foroplLcIsWatched();
	}

	/**
	 * Whether this user is inside the AI watch window: a configurable number
	 * of days counted from their first message (not from registration), so
	 * dormant accounts are still watched once they start posting.
	 */
	public function foroplLcIsWatched()
	{
		if (!$this->user_id)
		{
			return false;
		}

		$window = (int) $this->app()->options()->foroplLcMaxAccountAgeDays * 86400;

		$firstPostDate = $this->foropl_lc_first_post_date;
		if ($firstPostDate)
		{
			return (\XF::$time - $firstPostDate) < $window;
		}

		if (!$this->message_count)
		{
			return true;
		}

		return $this->register_date >= (\XF::$time - $window);
	}

	public static function getStructure(Structure $structure)
	{
		$structure = parent::getStructure($structure);

		$structure->columns['foropl_lc_first_post_date'] = ['type' => self::UINT, 'default' => 0];

		return $structure;
	}
}
