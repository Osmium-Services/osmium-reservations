<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * Whether a requested time and party size are ones the restaurant can take, from its opening hours, notice,
 * how-far-ahead and step. Pure: it is given the settings and "now", so it can be tested on its own.
 *
 * Only the start must fall inside the bookable hours: guests may still be at the table when the restaurant closes, so
 * the last bookable time is the day's closing time (or a narrower window set for it), not the end of the meal.
 */
class ReservationRules
{
    /**
     * @param array{hours: array<int, array{open: string, close: string, breakStart: ?string, breakEnd: ?string}|null>, minNoticeHours: int, maxDaysAhead: int, stepMinutes: int, diningMinutes: int} $config
     * @return array{startsAt: string, endsAt: string} Both as Y-m-d H:i:s; the end is when the table is free again
     * @throws \InvalidArgumentException with a message fit to show the customer
     */
    public static function slot(array $config, string $date, string $time, \DateTimeImmutable $now): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $now->getTimezone());
        $startBad = $start === false || $start->format('Y-m-d H:i') !== $date . ' ' . $time;
        if ($startBad) throw new \InvalidArgumentException('Please choose a date and time');

        $tooSoon = $start < $now->modify('+' . $config['minNoticeHours'] . ' hours');
        if ($tooSoon) throw new \InvalidArgumentException('Please allow at least ' . self::hoursText($config['minNoticeHours']) . ' notice');

        $tooFar = $start > $now->modify('+' . $config['maxDaysAhead'] . ' days');
        if ($tooFar) throw new \InvalidArgumentException('Tables can be requested up to ' . $config['maxDaysAhead'] . ' days ahead');

        $minutes = (int) $start->format('H') * 60 + (int) $start->format('i');
        $offStep = $minutes % $config['stepMinutes'] !== 0;
        if ($offStep) throw new \InvalidArgumentException('Please choose a time on the ' . $config['stepMinutes'] . ' minute marks');

        $hours = $config['hours'][(int) $start->format('N')] ?? null;
        if ($hours === null) throw new \InvalidArgumentException('We are closed on ' . $start->format('l') . 's');

        $at = $start->format('H:i');
        $outsideHours = $at < $hours['open'] || $at > $hours['close'];
        if ($outsideHours) throw new \InvalidArgumentException('We take bookings on ' . $start->format('l') . 's from ' . $hours['open'] . ' to ' . $hours['close']);

        $breakStart = $hours['breakStart'] ?? null;
        $breakEnd = $hours['breakEnd'] ?? null;
        $hasBreak = $breakStart !== null && $breakEnd !== null;
        $duringBreak = $hasBreak && $at >= $breakStart && $at < $breakEnd;
        if ($duringBreak) throw new \InvalidArgumentException('We are not taking bookings between ' . $breakStart . ' and ' . $breakEnd . ' on ' . $start->format('l') . 's');

        $end = $start->modify('+' . $config['diningMinutes'] . ' minutes');

        return ['startsAt' => $start->format('Y-m-d H:i:s'), 'endsAt' => $end->format('Y-m-d H:i:s')];
    }

    /**
     * The same checks for a reservation made or moved by staff: opening hours, closed days, breaks and the time step,
     * but no minimum notice, how-far-ahead limit or "not in the past" rule.
     *
     * @return array{startsAt: string, endsAt: string}
     * @throws \InvalidArgumentException
     */
    public static function staffSlot(array $config, \DateTimeImmutable $start): array
    {
        $relaxed = ['minNoticeHours' => 0, 'maxDaysAhead' => 100000] + $config;

        return self::slot(config: $relaxed, date: $start->format('Y-m-d'), time: $start->format('H:i'), now: new \DateTimeImmutable('2000-01-01'));
    }

    /**
     * Whether the party fits the restaurant at all, with a message fit for the customer when it does not.
     *
     * @param array{minParty: int, maxParty: int} $config
     * @param int $largestTable the seats of the biggest table that is switched on (0 when there are none)
     * @throws \InvalidArgumentException
     */
    public static function party(array $config, int $partySize, int $largestTable, string $phone = ''): void
    {
        $small = $partySize < $config['minParty'];
        if ($small) throw new \InvalidArgumentException('Please book for at least ' . $config['minParty'] . ($config['minParty'] === 1 ? ' person' : ' people'));

        $callUs = $phone !== '' ? ' Please call us on ' . $phone . '.' : ' Please contact us to arrange it.';
        $tooBig = $partySize > $config['maxParty'] || $partySize > $largestTable;
        if ($tooBig) throw new \InvalidArgumentException('We cannot take a party of ' . $partySize . ' online.' . $callUs);
    }

    /** Two reservations overlap when each starts before the other ends; touching ends are fine */
    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }

    private static function hoursText(int $hours): string
    {
        if ($hours % 24 === 0 && $hours > 0) {
            $days = \intdiv($hours, 24);

            return $days === 1 ? '1 day' : $days . ' days';
        }

        return $hours === 1 ? '1 hour' : $hours . ' hours';
    }
}
