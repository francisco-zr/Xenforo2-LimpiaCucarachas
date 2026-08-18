<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $reservation_id
 * @property int $user_id
 * @property string $reservation_key
 * @property string $content_type
 * @property int $reserve_date
 * @property int $expire_date
 */
class Reservation extends Entity
{
	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_foropl_lc_reservation';
		$structure->shortName = 'foroPL\LimpiaCucarachas:Reservation';
		$structure->primaryKey = 'reservation_id';
		$structure->columns = [
			'reservation_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'user_id' => ['type' => self::UINT, 'default' => 0],
			'reservation_key' => ['type' => self::BINARY, 'maxLength' => 64, 'default' => ''],
			'content_type' => ['type' => self::BINARY, 'maxLength' => 25, 'default' => ''],
			'reserve_date' => ['type' => self::UINT, 'default' => 0],
			'expire_date' => ['type' => self::UINT, 'default' => 0],
		];
		$structure->getters = [];
		$structure->relations = [];

		return $structure;
	}
}
