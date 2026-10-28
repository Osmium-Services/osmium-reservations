<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/** No table is free for the time asked for; carries the nearest times that day that do have one */
class NoTableException extends \InvalidArgumentException
{
    /**
     * @param list<string> $times H:i, in time order
     */
    public function __construct(string $message, public readonly array $times)
    {
        parent::__construct($message);
    }
}
