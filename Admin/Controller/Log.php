<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Admin\Controller;

use foroPL\LimpiaCucarachas\Entity\Log as LogEntity;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class Log extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertAdminPermission('viewLogs');
	}

	public function actionIndex(ParameterBag $params): AbstractReply
	{
		if ($params->log_id)
		{
			return $this->rerouteController(self::class, 'view', $params);
		}

		$page = $this->filterPage();
		$perPage = 50;

		$logFinder = $this->finder('foroPL\LimpiaCucarachas:Log')
			->with('User')
			->setDefaultOrder('log_date', 'DESC')
			->limitByPage($page, $perPage);

		$viewParams = [
			'entries' => $logFinder->fetch(),

			'page' => $page,
			'perPage' => $perPage,
			'total' => $logFinder->total(),
		];

		return $this->view(
			'foroPL\LimpiaCucarachas:Log\Listing',
			'foropl_lc_log_list',
			$viewParams
		);
	}

	public function actionView(ParameterBag $params): AbstractReply
	{
		$entry = $this->assertRecordExists(
			'foroPL\LimpiaCucarachas:Log',
			$params->log_id,
			'User',
			'requested_log_entry_not_found'
		);

		$viewParams = [
			'entry' => $entry,
		];

		return $this->view(
			'foroPL\LimpiaCucarachas:Log\View',
			'foropl_lc_log_view',
			$viewParams
		);
	}
}
