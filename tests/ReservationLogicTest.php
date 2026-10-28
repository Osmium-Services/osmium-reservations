<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumReservations;

use Osmium\Services\OsmiumReservations\Models\ReservationConfig;
use Osmium\Services\OsmiumReservations\Models\ReservationIcs;
use Osmium\Services\OsmiumReservations\Models\ReservationMail;
use Osmium\Services\OsmiumReservations\Models\ReservationRequest;
use Osmium\Services\OsmiumReservations\Models\ReservationRules;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/ReservationConfig.php';
require_once __DIR__ . '/../models/ReservationIcs.php';
require_once __DIR__ . '/../models/ReservationMail.php';
require_once __DIR__ . '/../models/ReservationRequest.php';
require_once __DIR__ . '/../models/ReservationRules.php';

/**
 * The rules, request cleaning, calendar file and email words: everything that needs no database.
 */
class ReservationLogicTest extends TestCase
{
    // A Monday morning
    private const NOW = '2026-11-02 08:00:00';

    /** Open Monday to Saturday 12:00 to 21:00 with a break from 14:30 to 18:00 on Mondays; closed Sundays */
    private function config(): array
    {
        $day = ['open' => '12:00', 'close' => '21:00', 'breakStart' => null, 'breakEnd' => null];
        $monday = ['breakStart' => '14:30', 'breakEnd' => '18:00'] + $day;

        return ReservationConfig::defaults() + ['hours' => [1 => $monday, 2 => $day, 3 => $day, 4 => $day, 5 => $day, 6 => $day, 7 => null]];
    }

    private function slot(string $date, string $time, ?array $config = null): array
    {
        return ReservationRules::slot(config: $config ?? $this->config(), date: $date, time: $time, now: new \DateTimeImmutable(self::NOW));
    }

    private function slotError(string $date, string $time): string
    {
        try {
            $this->slot($date, $time);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function test_a_good_time_gives_start_and_the_end_of_the_dining_time(): void
    {
        $this->assertSame(['startsAt' => '2026-11-05 19:30:00', 'endsAt' => '2026-11-05 21:00:00'], $this->slot('2026-11-05', '19:30'));

        $long = ['diningMinutes' => 120] + $this->config();
        $this->assertSame('2026-11-05 21:30:00', $this->slot('2026-11-05', '19:30', $long)['endsAt']);
    }

    public function test_a_meal_may_run_past_closing_when_it_starts_in_time(): void
    {
        $this->assertSame('2026-11-05 22:30:00', $this->slot('2026-11-05', '21:00')['endsAt']);
        $this->assertStringContainsString('from 12:00 to 21:00', $this->slotError('2026-11-05', '21:15'));
        $this->assertStringContainsString('from 12:00 to 21:00', $this->slotError('2026-11-05', '11:45'));
    }

    public function test_the_notice_the_closed_days_and_the_step_are_enforced(): void
    {
        $this->assertStringContainsString('closed on Sundays', $this->slotError('2026-11-08', '19:00'));
        $this->assertStringContainsString('at least 2 hours notice', $this->slotError('2026-11-02', '09:30'));
        $this->assertStringContainsString('15 minute marks', $this->slotError('2026-11-05', '19:10'));
        $this->assertStringContainsString('up to 90 days ahead', $this->slotError('2027-06-01', '19:00'));
        $this->assertStringContainsString('choose a date and time', $this->slotError('not a date', '19:00'));
    }

    public function test_nothing_can_start_in_the_break_but_lunch_and_dinner_can(): void
    {
        $this->assertSame('', $this->slotError('2026-11-09', '14:15'));
        $this->assertStringContainsString('between 14:30 and 18:00', $this->slotError('2026-11-09', '14:30'));
        $this->assertStringContainsString('between 14:30 and 18:00', $this->slotError('2026-11-09', '17:45'));
        $this->assertSame('', $this->slotError('2026-11-09', '18:00'));
        $this->assertSame('', $this->slotError('2026-11-10', '15:00'), 'Only Mondays have the break');
    }

    public function test_hours_come_from_the_company_and_the_break_and_window_are_added(): void
    {
        $company = ['Monday' => ['opens' => '09:00', 'closes' => '23:00'], 'Tuesday' => ['closed' => true]];
        $breaks = [1 => ['start' => '14:30', 'end' => '18:00']];
        $windows = [1 => ['start' => '12:00', 'end' => '21:30']];

        $hours = ReservationConfig::hoursFor(companyHours: $company, breaks: $breaks, windows: $windows);

        $this->assertSame(['open' => '12:00', 'close' => '21:30', 'breakStart' => '14:30', 'breakEnd' => '18:00'], $hours[1]);
        $this->assertNull($hours[2]);
        $this->assertNull($hours[3]);
    }

    public function test_staff_can_book_any_date_but_not_outside_the_hours(): void
    {
        $config = $this->config();
        $past = ReservationRules::staffSlot(config: $config, start: new \DateTimeImmutable('2026-10-01 19:00'));
        $this->assertSame('2026-10-01 20:30:00', $past['endsAt']);

        $this->expectException(\InvalidArgumentException::class);
        ReservationRules::staffSlot(config: $config, start: new \DateTimeImmutable('2026-11-08 19:00'));
    }

    public function test_a_party_too_small_or_too_big_for_the_restaurant_is_refused(): void
    {
        $config = ['minParty' => 2, 'maxParty' => 8];
        ReservationRules::party(config: $config, partySize: 4, largestTable: 6);

        $messages = [];
        foreach ([[1, 6], [7, 6], [9, 12]] as [$size, $largest]) {
            try {
                ReservationRules::party(config: $config, partySize: $size, largestTable: $largest, phone: '01234 567890');
            } catch (\InvalidArgumentException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame('Please book for at least 2 people', $messages[0]);
        $this->assertSame('We cannot take a party of 7 online. Please call us on 01234 567890.', $messages[1]);
        $this->assertStringContainsString('party of 9', $messages[2]);
    }

    public function test_overlap_ignores_touching_ends(): void
    {
        $this->assertTrue(ReservationRules::overlaps('2026-11-05 19:00:00', '2026-11-05 20:30:00', '2026-11-05 20:00:00', '2026-11-05 21:30:00'));
        $this->assertFalse(ReservationRules::overlaps('2026-11-05 19:00:00', '2026-11-05 20:30:00', '2026-11-05 20:30:00', '2026-11-05 22:00:00'));
    }

    public function test_settings_are_validated_saved_and_read_back(): void
    {
        $path = \sys_get_temp_dir() . '/osmium-reservations-test-' . \uniqid() . '.json.php';
        $settings = ['stepMinutes' => '30', 'diningMinutes' => '105', 'turnaroundMinutes' => '5', 'minParty' => '2', 'maxParty' => '10', 'minNoticeHours' => '3', 'maxDaysAhead' => '60', 'notifyEmails' => "host@example.com\nmanager@example.com", 'breaks' => [1 => ['start' => '14:30', 'end' => '18:00']]];

        ReservationConfig::save($settings, $path);
        $read = ReservationConfig::stored($path);
        \unlink($path);

        $this->assertSame(30, $read['stepMinutes']);
        $this->assertSame(105, $read['diningMinutes']);
        $this->assertSame(5, $read['turnaroundMinutes']);
        $this->assertSame(2, $read['minParty']);
        $this->assertSame(10, $read['maxParty']);
        $this->assertSame(['host@example.com', 'manager@example.com'], $read['notifyEmails']);
        $this->assertSame(['start' => '14:30', 'end' => '18:00'], $read['breaks'][1]);
    }

    public function test_bad_settings_are_refused(): void
    {
        $good = ['stepMinutes' => 15, 'diningMinutes' => 90, 'turnaroundMinutes' => 5, 'minParty' => 1, 'maxParty' => 8, 'minNoticeHours' => 2, 'maxDaysAhead' => 90, 'notifyEmails' => ''];
        $this->assertIsArray(ReservationConfig::validate($good));

        foreach ([['diningMinutes' => 5], ['diningMinutes' => 600], ['turnaroundMinutes' => 61], ['turnaroundMinutes' => -1], ['minParty' => 0], ['maxParty' => 0], ['minParty' => 5, 'maxParty' => 4], ['stepMinutes' => 7], ['notifyEmails' => 'nonsense']] as $change) {
            try {
                ReservationConfig::validate($change + $good);
                $this->fail('Should have been refused: ' . \json_encode($change));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_a_request_is_cleaned_and_bad_ones_refused(): void
    {
        $clean = ReservationRequest::parse(['party_size' => '4', 'date' => '2026-11-05', 'time' => '19:30', 'name' => '  Pat  ', 'email' => 'pat@example.com', 'phone' => '', 'notes' => ' by the window ']);
        $this->assertSame(4, $clean['partySize']);
        $this->assertSame('Pat', $clean['name']);
        $this->assertNull($clean['phone']);
        $this->assertSame('by the window', $clean['notes']);

        $good = ['party_size' => '2', 'name' => 'Pat', 'email' => 'pat@example.com'];
        foreach ([['party_size' => '0'], ['party_size' => '500'], ['name' => ''], ['email' => 'nope'], ['phone' => 'abc']] as $change) {
            try {
                ReservationRequest::parse($change + $good);
                $this->fail('Should have been refused: ' . \json_encode($change));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_the_calendar_file_has_a_stable_uid_and_a_rising_sequence(): void
    {
        $row = ['reference' => 'ABC123XYZ9', 'starts_at' => '2026-11-05 19:30:00', 'ends_at' => '2026-11-05 21:00:00', 'status' => 'confirmed', 'ics_sequence' => 0];
        $stamp = new \DateTimeImmutable('2026-11-01 10:00:00', new \DateTimeZone('UTC'));

        $first = ReservationIcs::build($row, 'Table at Bistro', 'Table for 4', 'example.com', $stamp);
        $moved = ReservationIcs::build(['ics_sequence' => 1, 'starts_at' => '2026-11-05 20:00:00'] + $row, 'Table at Bistro', 'Table for 4', 'example.com', $stamp);
        $cancelled = ReservationIcs::build(['status' => 'cancelled', 'ics_sequence' => 2] + $row, 'Table at Bistro', 'Table for 4', 'example.com', $stamp);

        $this->assertStringContainsString('UID:ABC123XYZ9@example.com', $first);
        $this->assertStringContainsString('SEQUENCE:0', $first);
        $this->assertStringContainsString('SEQUENCE:1', $moved);
        $this->assertStringContainsString('UID:ABC123XYZ9@example.com', $moved);
        $this->assertStringContainsString('METHOD:CANCEL', $cancelled);
        $this->assertStringContainsString('STATUS:CANCELLED', $cancelled);
    }

    public function test_emails_show_the_time_and_party_but_never_the_dining_time_or_table(): void
    {
        $row = ['customer_name' => 'Pat', 'reference' => 'ABC123XYZ9', 'party_size' => 4, 'starts_at' => '2026-11-05 19:30:00', 'ends_at' => '2026-11-05 21:00:00', 'table_name' => 'Window 1', 'table_seats' => 4];

        foreach ([ReservationMail::received($row, 'Bistro'), ReservationMail::confirmed($row, 'Bistro', 'https://x/ics', 'https://x/g'), ReservationMail::changed($row, 'Bistro', 'https://x/ics', 'https://x/g'), ReservationMail::declined($row, 'Bistro'), ReservationMail::cancelled($row, 'Bistro')] as $mail) {
            $this->assertStringContainsString('Thursday 5 November 2026 at 19:30', $mail['html']);
            $this->assertStringContainsString('4 people', $mail['html']);
            $this->assertStringNotContainsString('21:00', $mail['html']);
            $this->assertStringNotContainsString('Window 1', $mail['html']);
        }
    }

    public function test_a_decline_or_cancel_email_carries_the_message_and_the_phone_number(): void
    {
        $row = ['customer_name' => 'Pat', 'reference' => 'ABC123XYZ9', 'party_size' => 1, 'starts_at' => '2026-11-05 19:30:00', 'ends_at' => '2026-11-05 21:00:00'];

        $declined = ReservationMail::declined($row, 'Bistro', 'We are full that night', '01234 567890');
        $this->assertStringContainsString('We are full that night', $declined['html']);
        $this->assertStringContainsString('01234 567890', $declined['html']);
        $this->assertStringContainsString('1 person', $declined['html']);
    }

    public function test_emails_escape_what_the_customer_typed(): void
    {
        $row = ['customer_name' => '<b>Pat</b>', 'customer_email' => 'p@example.com', 'customer_phone' => null, 'customer_notes' => '<script>alert(1)</script>', 'reference' => 'ABC123XYZ9', 'party_size' => 2, 'starts_at' => '2026-11-05 19:30:00', 'ends_at' => '2026-11-05 21:00:00'];

        $team = ReservationMail::requestForTeam($row, 'Bistro', 'https://x/admin');

        $this->assertStringNotContainsString('<script>', $team['html']);
        $this->assertStringNotContainsString('<b>Pat</b>', $team['html']);
    }
}
