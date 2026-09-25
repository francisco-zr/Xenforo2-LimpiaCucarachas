<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $log_id
 * @property int $log_date
 * @property int $user_id
 * @property string $content_type
 * @property int $content_id
 * @property string $verdict
 * @property string $reason
 * @property int $response_ms
 * @property string $snippet
 * @property int $moderator_user_id
 *
 * RELATIONS
 * @property-read \XF\Entity\User|null $User
 * @property-read \XF\Entity\User|null $Moderator
 */
class Log extends Entity
{
	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_foropl_lc_log';
		$structure->shortName = 'foroPL\LimpiaCucarachas:Log';
		$structure->primaryKey = 'log_id';
		$structure->columns = [
			'log_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'log_date' => ['type' => self::UINT, 'default' => \XF::$time],
			'user_id' => ['type' => self::UINT, 'default' => 0],
			'content_type' => ['type' => self::BINARY, 'maxLength' => 25, 'default' => ''],
			'content_id' => ['type' => self::UINT, 'default' => 0],
			'verdict' => ['type' => self::BINARY, 'maxLength' => 25,
				'allowedValues' => [
					'spam', 'ham', 'denied_flood', 'error', 'edited_in_queue',
					'approved', 'confirmed_spam',
				],
				'default' => 'error',
			],
			'reason' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
			'response_ms' => ['type' => self::UINT, 'default' => 0],
			'snippet' => ['type' => self::STR, 'maxLength' => 500, 'default' => ''],
			'moderator_user_id' => ['type' => self::UINT, 'default' => 0],
		];
		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true,
			],
			'Moderator' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => [['user_id', '=', '$moderator_user_id']],
				'primary' => true,
			],
		];

		return $structure;
	}
}
