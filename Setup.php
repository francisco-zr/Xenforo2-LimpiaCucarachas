<?php

namespace foroPL\LimpiaCucarachas;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function installStep1()
	{
		$this->schemaManager()->createTable('xf_foropl_lc_log', function (Create $table)
		{
			$table->addColumn('log_id', 'int')->autoIncrement();
			$table->addColumn('log_date', 'int')->setDefault(0);
			$table->addColumn('user_id', 'int')->setDefault(0);
			$table->addColumn('content_type', 'varbinary', 25)->setDefault('');
			$table->addColumn('verdict', 'varbinary', 25)->setDefault('');
			$table->addColumn('reason', 'varchar', 255)->setDefault('');
			$table->addColumn('response_ms', 'int')->setDefault(0);
			$table->addColumn('snippet', 'varchar', 500)->setDefault('');
			$table->addPrimaryKey('log_id');
			$table->addKey('log_date');
			$table->addKey('user_id');
		});
	}

	public function installStep2()
	{
		$this->schemaManager()->alterTable('xf_user', function (Alter $table)
		{
			$table->addColumn('foropl_lc_first_post_date', 'int')->setDefault(0);
		});
	}

	public function upgrade1000170Step1()
	{
		$this->installStep2();
	}

	/**
	 * 1.0.6 removed the atomic reservation (idempotency) mechanism, its table
	 * and its cleanup cron. Drop the leftover table and cron entry on existing
	 * installs so the removal is clean.
	 */
	public function upgrade1000670Step1()
	{
		if ($this->schemaManager()->tableExists('xf_foropl_lc_reservation'))
		{
			$this->schemaManager()->dropTable('xf_foropl_lc_reservation');
		}

		\XF::db()->delete('xf_cron_entry', 'entry_id = ?', 'foroplLcResCleanUp');
	}

	public function uninstallStep1()
	{
		$this->schemaManager()->dropTable('xf_foropl_lc_log');
	}

	public function uninstallStep2()
	{
		$this->schemaManager()->alterTable('xf_user', function (Alter $table)
		{
			$table->dropColumns(['foropl_lc_first_post_date']);
		});
	}
}
