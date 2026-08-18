<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Repository;

use foroPL\LimpiaCucarachas\Entity\Reservation;
use foroPL\LimpiaCucarachas\Finder\ReservationFinder;
use XF\Entity\User;
use XF\Finder\PostFinder;
use XF\Finder\ProfilePostCommentFinder;
use XF\Finder\ProfilePostFinder;
use XF\Finder\ThreadFinder;
use XF\Mvc\Entity\Repository;

class Queue extends Repository
{
	protected $requestReservations = [];

	public function reserveQueueSlot(
		User $user,
		string $contentType,
		int $maxQueuedMessages,
		int $contentId = 0
	): array
	{
		$requestKey = $contentId > 0 ? $contentType . ':' . $contentId : null;
		if ($requestKey !== null && isset($this->requestReservations[$requestKey]))
		{
			return $this->requestReservations[$requestKey];
		}

		$db = $this->db();
		$userId = (int) $user->user_id;
		$now = \XF::$time;
		$inTransaction = false;
		$reservationKey = null;

		try
		{
			$db->beginTransaction();
			$inTransaction = true;

			// Every reservation for a user takes this same short-lived lock.
			$db->fetchOne(
				' SELECT user_id FROM xf_user WHERE user_id = ? FOR UPDATE',
				$userId
			);

			$expiredReservations = $this->finder(ReservationFinder::class)
				->where('user_id', $userId)
				->where('expire_date', '<=', $now)
				->fetch();
			foreach ($expiredReservations AS $expiredReservation)
			{
				$expiredReservation->delete(false);
			}

			$queuedCount = $this->getModeratedContentCount($userId);
			$reservedCount = $this->finder(ReservationFinder::class)
				->where('user_id', $userId)
				->where('expire_date', '>', $now)
				->total();

			if ($queuedCount + $reservedCount >= max(1, $maxQueuedMessages))
			{
				$db->commit();
				$inTransaction = false;

				$result = [
					'reserved' => false,
					'queued_count' => $queuedCount,
					'reserved_count' => $reservedCount,
				];
				if ($requestKey !== null)
				{
					$this->requestReservations[$requestKey] = $result;
				}

				return $result;
			}

			$reservationKey = bin2hex(random_bytes(16));
			$reservationTtl = max(300, (int) \XF::options()->foroplLcTimeout + 120);

			/** @var Reservation $reservation */
			$reservation = $this->em->create(Reservation::class);
			$reservation->user_id = $userId;
			$reservation->reservation_key = $reservationKey;
			$reservation->content_type = $contentType;
			$reservation->reserve_date = $now;
			$reservation->expire_date = $now + $reservationTtl;
			$reservation->save();

			$db->commit();
			$inTransaction = false;
		}
		catch (\Throwable $e)
		{
			if ($inTransaction)
			{
				$db->rollback();
			}

			throw $e;
		}

		register_shutdown_function([$this, 'releaseReservation'], $reservationKey);

		$result = [
			'reserved' => true,
			'reservation_key' => $reservationKey,
			'queued_count' => $queuedCount,
			'reserved_count' => $reservedCount,
		];
		if ($requestKey !== null)
		{
			$this->requestReservations[$requestKey] = $result;
		}

		return $result;
	}

	public function releaseReservation(string $reservationKey): void
	{
		try
		{
			$reservation = $this->finder(ReservationFinder::class)
				->where('reservation_key', $reservationKey)
				->fetchOne();
			if ($reservation)
			{
				$reservation->delete();
			}
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'LimpiaCucarachas reservation cleanup error: ');
		}
	}

	public function cleanUpExpired(int $limit = 1000): int
	{
		$limit = max(1, min(1000, $limit));
		$result = $this->db()->query(
			'DELETE FROM xf_foropl_lc_reservation WHERE expire_date <= ? LIMIT ' . $limit,
			\XF::$time
		);

		return $result->rowsAffected();
	}

	protected function getModeratedContentCount(int $userId): int
	{
		$posts = $this->finder(PostFinder::class)
			->where('user_id', $userId)
			->where('message_state', 'moderated')
			->total();
		$threads = $this->finder(ThreadFinder::class)
			->where('user_id', $userId)
			->where('discussion_state', 'moderated')
			->total();
		$profilePosts = $this->finder(ProfilePostFinder::class)
			->where('user_id', $userId)
			->where('message_state', 'moderated')
			->total();
		$profilePostComments = $this->finder(ProfilePostCommentFinder::class)
			->where('user_id', $userId)
			->where('message_state', 'moderated')
			->total();

		return $posts + $threads + $profilePosts + $profilePostComments;
	}
}
