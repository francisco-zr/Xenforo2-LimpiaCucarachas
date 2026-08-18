<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Finder;

use foroPL\LimpiaCucarachas\Entity\Reservation;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Finder;

/**
 * @method AbstractCollection<Reservation> fetch(?int $limit = null, ?int $offset = null)
 * @method AbstractCollection<Reservation> fetchDeferred(?int $limit = null, ?int $offset = null)
 * @method Reservation|null fetchOne(?int $offset = null)
 * @extends Finder<Reservation>
 */
class ReservationFinder extends Finder
{
}
