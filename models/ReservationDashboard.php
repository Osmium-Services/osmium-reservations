<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * The dashboard card: how many requests are waiting for an answer, and the first few.
 */
class ReservationDashboard
{
    /**
     * @param array{osmium: object, role?: string} $payload
     * @return array<string, mixed>
     */
    public static function widget(array $payload): array
    {
        $model = new Reservation($payload['osmium']->dataSource);
        $waiting = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => $row['status'] === 'requested'));

        $lines = [];
        foreach (\array_slice($waiting, 0, 5) as $row) {
            $lines[] = [
                'text' => (string) $row['customer_name'] . ', party of ' . (int) $row['party_size'],
                'sub' => (new \DateTimeImmutable($row['starts_at']))->format('D j M, H:i'),
                'route' => 'reservations',
            ];
        }

        return [
            'id' => 'osmium-reservations',
            'title' => 'Table requests',
            'icon' => 'bx-restaurant',
            'roles' => ['admin'],
            'order' => 20,
            'count' => \count($waiting),
            'link' => ['label' => 'Open reservations', 'route' => 'reservations'],
            'lines' => $lines,
            'emptyText' => 'No requests waiting.',
        ];
    }
}
