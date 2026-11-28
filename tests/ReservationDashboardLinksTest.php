<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumReservations;

use Osmium\Services\OsmiumReservations\Models\ReservationDashboardLinks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/ReservationDashboardLinks.php';

/**
 * The dashboard tile opens the reservations list and says how many requests are waiting.
 */
final class ReservationDashboardLinksTest extends TestCase
{
    public function test_the_tile_opens_the_list(): void
    {
        $tile = ReservationDashboardLinks::tile(waiting: 0);

        $this->assertSame('reservations/', $tile['url']);
        $this->assertSame('Manage Reservations', $tile['title']);
        $this->assertSame('Reservations', $tile['label']);
        $this->assertSame(['label', 'title', 'icon', 'button', 'url', 'attention'], \array_keys($tile));
    }

    public function test_the_icon_asks_for_attention_only_when_requests_wait(): void
    {
        $this->assertSame('', ReservationDashboardLinks::tile(waiting: 0)['attention']);
        $this->assertSame('1', ReservationDashboardLinks::tile(waiting: 2)['attention']);
    }

    public function test_the_label_counts_waiting_requests(): void
    {
        $this->assertSame('Reservations · 3 waiting', ReservationDashboardLinks::tile(waiting: 3)['label']);
    }
}
