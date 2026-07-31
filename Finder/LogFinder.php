<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Finder;

use foroPL\LimpiaCucarachas\Entity\Log;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Finder;

/**
 * @method AbstractCollection<Log> fetch(?int $limit = null, ?int $offset = null)
 * @method AbstractCollection<Log> fetchDeferred(?int $limit = null, ?int $offset = null)
 * @method Log|null fetchOne(?int $offset = null)
 * @extends Finder<Log>
 */
class LogFinder extends Finder
{
}
