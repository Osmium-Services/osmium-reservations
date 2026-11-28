<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumReservations;

use Osmium\Services\OsmiumReservations\Models\ReservationStatusIcon;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/ReservationStatusIcon.php';

/**
 * The navbar icon is amber, says how many requests wait, and opens the list.
 */
final class ReservationStatusIconTest extends TestCase
{
    public function test_the_icon_is_amber_and_opens_the_list(): void
    {
        $icon = ReservationStatusIcon::icon(waiting: 3);

        $this->assertSame('warning', $icon['color']);
        $this->assertSame('reservations/', $icon['path']);
        $this->assertSame('bx bx-restaurant', $icon['icon']);
        $this->assertSame('3 table requests waiting', $icon['title']);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $icon['id']);
    }

    public function test_one_request_is_singular(): void
    {
        $this->assertSame('1 table request waiting', ReservationStatusIcon::icon(waiting: 1)['title']);
    }
}
