<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * admin.status_icons: an amber icon in the admin navbar while table requests are waiting for an answer, nothing otherwise.
 * One count query, no network.
 */
class ReservationStatusIcon
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public static function icons(array $payload): array
    {
        $waiting = (new Reservation($payload['osmium']->dataSource))->countRequested();

        return $waiting > 0 ? [self::icon(waiting: $waiting)] : [];
    }

    /** @return array<string, mixed> */
    public static function icon(int $waiting): array
    {
        return [
            'id' => 'reservations-waiting',
            'title' => $waiting . ' table ' . ($waiting === 1 ? 'request' : 'requests') . ' waiting',
            'icon' => 'bx bx-restaurant',
            'path' => 'reservations/',
            'color' => 'warning',
            'crossed' => false,
        ];
    }
}
