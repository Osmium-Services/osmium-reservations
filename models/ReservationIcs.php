<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * The calendar side of a confirmed reservation: an .ics file a customer can open in any calendar, and an "add to Google
 * Calendar" link. Pure, so it can be tested on its own. Times are written in UTC.
 *
 * The same reservation always gets the same UID. A changed reservation carries a higher SEQUENCE, and a cancelled one is
 * marked CANCELLED, which is how calendars know it replaces the earlier copy.
 */
class ReservationIcs
{
    /**
     * @param array{reference: string, starts_at: string, ends_at: string, status: string, ics_sequence: int|string} $reservation
     * @param string $summary The event title, such as "Haircut at Acme Salon"
     */
    public static function build(array $reservation, string $summary, string $description, string $host, \DateTimeImmutable $stamp): string
    {
        $cancelled = $reservation['status'] === 'cancelled';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Osmium//Reservations//EN',
            'CALSCALE:GREGORIAN',
            $cancelled ? 'METHOD:CANCEL' : 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $reservation['reference'] . '@' . $host,
            'DTSTAMP:' . self::utc($stamp->format('Y-m-d H:i:s'), $stamp->getTimezone()),
            'DTSTART:' . self::utc($reservation['starts_at'], $stamp->getTimezone()),
            'DTEND:' . self::utc($reservation['ends_at'], $stamp->getTimezone()),
            'SEQUENCE:' . (int) $reservation['ics_sequence'],
            'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'SUMMARY:' . self::escape($summary),
            'DESCRIPTION:' . self::escape($description),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return \implode("\r\n", \array_map(self::fold(...), $lines)) . "\r\n";
    }

    /** A link that opens Google Calendar with the event filled in, ready to save: no API and no sign-in on our side */
    public static function googleLink(array $reservation, string $summary, string $description, \DateTimeZone $zone): string
    {
        $query = \http_build_query([
            'action' => 'TEMPLATE',
            'text' => $summary,
            'dates' => self::utc($reservation['starts_at'], $zone) . '/' . self::utc($reservation['ends_at'], $zone),
            'details' => $description,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://calendar.google.com/calendar/render?' . $query;
    }

    /** A local date and time as a UTC stamp, 20261104T093000Z */
    public static function utc(string $local, \DateTimeZone $zone): string
    {
        $time = new \DateTimeImmutable($local, $zone);

        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        $text = \str_replace(['\\', ';', ','], ['\\\\', '\;', '\\,'], $text);

        return \str_replace(["\r\n", "\n", "\r"], '\\n', $text);
    }

    /** Lines over 75 bytes are folded onto continuation lines that start with a space, as the format requires */
    private static function fold(string $line): string
    {
        $isShort = \strlen($line) <= 75;
        if ($isShort) return $line;

        $parts = [];
        $rest = $line;
        $limit = 75;
        while (\strlen($rest) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (\ord($rest[$cut]) & 0xC0) === 0x80) $cut--; // Never split inside a multi-byte character
            $parts[] = \substr($rest, 0, $cut);
            $rest = \substr($rest, $cut);
            $limit = 74;
        }
        $parts[] = $rest;

        return \implode("\r\n ", $parts);
    }
}
