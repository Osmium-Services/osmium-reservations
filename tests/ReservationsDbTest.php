<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumReservations;

use Osmium\Services\OsmiumReservations\Models\Reservation;
use Osmium\Services\OsmiumReservations\Models\ReservationAlternatives;
use Osmium\Services\OsmiumReservations\Models\ReservationConfig;
use Osmium\Services\OsmiumReservations\Models\ReservationTable;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/ReservationConfig.php';
require_once __DIR__ . '/../models/ReservationRules.php';
require_once __DIR__ . '/../models/ReservationTable.php';
require_once __DIR__ . '/../models/Reservation.php';
require_once __DIR__ . '/../models/ReservationAlternatives.php';

/**
 * The reservation tables against the installed schema (rolled back with each test; the service must be installed,
 * because creating the tables here would commit the test's transaction, and its tables must be empty).
 */
class ReservationsDbTest extends DatabaseTestCase
{
    private function table(string $name, int $seats, bool $enabled = true): int
    {
        return (new ReservationTable($this->db))->create(name: $name, seats: $seats, enabled: $enabled, sortOrder: 0);
    }

    private function request(int $partySize = 2, ?int $tableId = null, string $email = 'pat@example.com'): array
    {
        return ['partySize' => $partySize, 'tableId' => $tableId, 'name' => 'Pat', 'email' => $email, 'phone' => null, 'notes' => null];
    }

    private function confirmed(Reservation $reservations, int $partySize, int $tableId, string $startsAt, string $endsAt, string $email = 'pat@example.com'): int
    {
        $made = $reservations->create($this->request($partySize, $tableId, $email), $startsAt, $endsAt);
        $reservations->setStatus(id: $made['id'], status: 'confirmed', sequence: 0);

        return $made['id'];
    }

    public function test_a_request_is_stored_as_requested_with_a_unique_reference(): void
    {
        $reservations = new Reservation($this->db);

        $first = $reservations->create($this->request(4), '2026-11-05 19:00:00', '2026-11-05 20:30:00');
        $second = $reservations->create($this->request(2, null, 'sam@example.com'), '2026-11-05 19:00:00', '2026-11-05 20:30:00');

        $row = $reservations->get($first['id']);
        $this->assertSame('requested', $row['status']);
        $this->assertSame(4, (int) $row['party_size']);
        $this->assertNull($row['table_id']);
        $this->assertSame(10, \strlen($first['reference']));
        $this->assertNotSame($first['reference'], $second['reference']);
        $this->assertSame($first['id'], (int) $reservations->getByReference($first['reference'])['id']);
    }

    public function test_the_same_person_asking_twice_for_the_same_time_is_a_duplicate(): void
    {
        $reservations = new Reservation($this->db);
        $reservations->create($this->request(), '2026-11-05 19:00:00', '2026-11-05 20:30:00');

        $this->assertTrue($reservations->isDuplicate('pat@example.com', '2026-11-05 19:00:00'));
        $this->assertFalse($reservations->isDuplicate('pat@example.com', '2026-11-05 19:15:00'));
        $this->assertFalse($reservations->isDuplicate('other@example.com', '2026-11-05 19:00:00'));
    }

    public function test_the_smallest_table_that_fits_is_chosen(): void
    {
        $reservations = new Reservation($this->db);
        $this->table('zzBig', 6);
        $four = $this->table('zzMid', 4);
        $two = $this->table('zzSmall', 2);

        $this->assertSame($two, (int) $reservations->findFreeTable(2, '2026-11-05 19:00:00', '2026-11-05 20:30:00')['id']);
        $this->assertSame($four, (int) $reservations->findFreeTable(3, '2026-11-05 19:00:00', '2026-11-05 20:30:00')['id']);
        $this->assertSame($four, (int) $reservations->findFreeTable(4, '2026-11-05 19:00:00', '2026-11-05 20:30:00')['id']);
        $this->assertNull($reservations->findFreeTable(7, '2026-11-05 19:00:00', '2026-11-05 20:30:00'));
    }

    public function test_a_table_switched_off_is_never_given(): void
    {
        $reservations = new Reservation($this->db);
        $this->table('zzOff', 4, false);

        $this->assertNull($reservations->findFreeTable(2, '2026-11-05 19:00:00', '2026-11-05 20:30:00'));
        $this->assertSame(0, (new ReservationTable($this->db))->largestParty());
    }

    public function test_only_a_confirmed_reservation_holds_a_table(): void
    {
        $reservations = new Reservation($this->db);
        $table = $this->table('zzOnly', 4);
        $this->confirmed($reservations, 4, $table, '2026-11-05 19:00:00', '2026-11-05 20:30:00');
        $reservations->create($this->request(2, $table, 'x@example.com'), '2026-11-05 12:00:00', '2026-11-05 13:30:00');

        $this->assertNull($reservations->findFreeTable(2, '2026-11-05 19:30:00', '2026-11-05 21:00:00'), 'Overlaps the confirmed one');
        $this->assertNotNull($reservations->findFreeTable(2, '2026-11-05 12:30:00', '2026-11-05 14:00:00'), 'A waiting request holds nothing');
        $this->assertNotNull($reservations->findFreeTable(2, '2026-11-05 20:30:00', '2026-11-05 22:00:00'), 'Touching ends are fine');
        $this->assertNotNull($reservations->findFreeTable(2, '2026-11-05 17:30:00', '2026-11-05 19:00:00'));
    }

    public function test_a_reservation_never_clashes_with_itself_and_cancelled_ones_hold_nothing(): void
    {
        $reservations = new Reservation($this->db);
        $table = $this->table('zzSelf', 2);
        $id = $this->confirmed($reservations, 2, $table, '2026-11-05 19:00:00', '2026-11-05 20:30:00');

        $this->assertNull($reservations->findFreeTable(2, '2026-11-05 19:00:00', '2026-11-05 20:30:00'));
        $this->assertSame($table, (int) $reservations->findFreeTable(2, '2026-11-05 19:00:00', '2026-11-05 20:30:00', $id)['id']);
        $this->assertNull($reservations->findOverlap(excludeId: $id, tableId: $table, startsAt: '2026-11-05 19:00:00', endsAt: '2026-11-05 20:30:00'));

        $reservations->setStatus(id: $id, status: 'cancelled', sequence: 1);
        $this->assertNotNull($reservations->findFreeTable(2, '2026-11-05 19:00:00', '2026-11-05 20:30:00'));
    }

    public function test_the_earliest_overlapping_reservation_is_named(): void
    {
        $reservations = new Reservation($this->db);
        $table = $this->table('zzEarly', 4);
        $this->confirmed($reservations, 2, $table, '2026-11-05 20:00:00', '2026-11-05 21:30:00', 'late@example.com');
        $first = $this->confirmed($reservations, 2, $table, '2026-11-05 18:30:00', '2026-11-05 20:00:00', 'early@example.com');

        $clash = $reservations->findOverlap(excludeId: 0, tableId: $table, startsAt: '2026-11-05 19:00:00', endsAt: '2026-11-05 21:00:00');

        $this->assertSame($first, (int) $clash['id']);
    }

    public function test_the_biggest_table_sets_the_largest_party(): void
    {
        $this->table('zzA', 2);
        $this->table('zzB', 6);
        $this->table('zzC', 4);

        $this->assertSame(6, (new ReservationTable($this->db))->largestParty());
    }

    public function test_a_table_in_use_shows_its_reservation_count(): void
    {
        $reservations = new Reservation($this->db);
        $table = $this->table('zzCount', 2);
        $reservations->create($this->request(2, $table), '2026-11-05 19:00:00', '2026-11-05 20:30:00');

        $row = \array_values(\array_filter((new ReservationTable($this->db))->listAll(), static fn(array $row): bool => (int) $row['id'] === $table))[0];

        $this->assertSame(1, (int) $row['reservation_count']);
    }

    public function test_a_refusal_offers_the_nearest_earlier_and_later_times(): void
    {
        $reservations = new Reservation($this->db);
        $table = $this->table('zzOnlyTable', 4);
        $this->confirmed($reservations, 4, $table, '2026-11-05 19:00:00', '2026-11-05 20:30:00');

        $day = ['open' => '12:00', 'close' => '21:00', 'breakStart' => null, 'breakEnd' => null];
        $config = ReservationConfig::defaults() + ['hours' => [4 => $day]];
        $config['minNoticeHours'] = 0;
        $alternatives = new ReservationAlternatives(reservations: $reservations, config: $config, now: new \DateTimeImmutable('2026-11-02 08:00:00'));

        $message = $alternatives->message(date: '2026-11-05', time: '19:30', partySize: 4);

        $this->assertSame('Sorry, we have no table for 4 people at 19:30. We could do 17:30 or 20:30 that day.', $message);
        $this->assertTrue($alternatives->isFree(4, '2026-11-05 20:30:00', '2026-11-05 22:00:00'));
        $this->assertFalse($alternatives->isFree(4, '2026-11-05 19:30:00', '2026-11-05 21:00:00'));
    }

    public function test_a_deleted_reservation_leaves_lists_and_comes_back_when_restored(): void
    {
        $reservations = new Reservation($this->db);
        $made = $reservations->create($this->request(2, null, 'bin@example.com'), '2026-11-05 19:00:00', '2026-11-05 20:30:00');

        $reservations->softDelete($made['id']);
        $this->assertNull($reservations->getByReference($made['reference']));
        $this->assertContains($made['id'], \array_map(static fn(array $row): int => (int) $row['id'], $reservations->listDeleted()));

        $reservations->restore($made['id']);
        $this->assertNotNull($reservations->getByReference($made['reference']));
    }
}
