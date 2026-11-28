<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * What the reservations service adds to the dashboard's quick links tile row (dashboard.quick_links): the label says how many requests are waiting.
 */
class ReservationDashboardLinks
{
    /**
     * @param array{osmium: object} $payload
     * @return array<string, string> the Manage Reservations tile
     */
    public static function quickLink(array $payload): array
    {
        $model = new Reservation($payload['osmium']->dataSource);
        $waiting = $model->countRequested();

        return self::tile(waiting: $waiting);
    }

    /** @return array<string, string> */
    public static function tile(int $waiting): array
    {
        return [
            'label' => 'Reservations' . ($waiting > 0 ? " · {$waiting} waiting" : ''),
            'title' => 'Manage Reservations',
            'icon' => 'bx-restaurant',
            'button' => 'View Reservations',
            'url' => 'reservations/',
            'attention' => $waiting > 0 ? '1' : '',
        ];
    }
}
