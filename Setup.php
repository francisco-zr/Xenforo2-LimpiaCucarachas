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

	public function installStep3()
	{
		$this->createReservationTable();
	}

	public function upgrade1000170Step1()
	{
		$this->installStep2();
	}

	public function upgrade1000570Step1()
	{
		$this->createReservationTable();
	}

	protected function createReservationTable(): void
	{
		$this->schemaManager()->createTable('xf_foropl_lc_reservation', function (Create $table)
		{
			$table->addColumn('reservation_id', 'int')->autoIncrement();
			$table->addColumn('user_id', 'int');
			$table->addColumn('reservation_key', 'varbinary', 64);
			$table->addColumn('content_type', 'varbinary', 25)->setDefault('');
			$table->addColumn('reserve_date', 'int')->setDefault(0);
			$table->addColumn('expire_date', 'int')->setDefault(0);
			$table->addPrimaryKey('reservation_id');
			$table->addUniqueKey('reservation_key');
			$table->addKey(['user_id', 'expire_date'], 'user_id_expire_date');
			$table->addKey('expire_date');
		});
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

	public function uninstallStep3()
	{
		$this->schemaManager()->dropTable('xf_foropl_lc_reservation');
	}
}
