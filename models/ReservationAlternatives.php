<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * What a good host would say when there is no table for the time asked for: the nearest times the same day when there
 * is one for the same number of people. All text is written for the customer.
 */
class ReservationAlternatives
{
    private const MAX_TIMES = 2;

    /**
     * @param array<string, mixed> $config the rules from ReservationConfig::get() (with hours)
     */
    public function __construct(private Reservation $reservations, private array $config, private \DateTimeImmutable $now)
    {
    }

    /** Free means some enabled table with enough seats has no confirmed reservation overlapping the time (or its turnaround) */
    public function isFree(int $partySize, string $startsAt, string $endsAt): bool
    {
        return $this->reservations->findFreeTable(partySize: $partySize, startsAt: $startsAt, endsAt: $endsAt, turnaroundMinutes: (int) ($this->config['turnaroundMinutes'] ?? 0)) !== null;
    }

    /** The refusal to show the customer, with what else could work */
    public function message(string $date, string $time, int $partySize): string
    {
        $opening = 'Sorry, we have no table for ' . $partySize . ($partySize === 1 ? ' person' : ' people') . ' at ' . $time . '.';
        $times = $this->nearestTimes(date: $date, requested: $time, partySize: $partySize);

        if ($times === []) return $opening . ' Please try another day.';

        return $opening . ' We could do ' . $this->join($times, 'or') . ' that day.';
    }

    /**
     * The start times closest to the one asked for on the same day that have a table: one earlier and one later where
     * possible
     *
     * @return list<string> H:i, in time order
     */
    private function nearestTimes(string $date, string $requested, int $partySize): array
    {
        $hours = $this->config['hours'][(int) (new \DateTimeImmutable($date))->format('N')] ?? null;
        if ($hours === null) return [];

        $step = (int) $this->config['stepMinutes'];
        $wanted = $this->minutesOf($requested);
        $candidates = [];
        for ($at = $this->minutesOf($hours['open']); $at <= $this->minutesOf($hours['close']); $at += $step) {
            if ($at % $step !== 0 || $at === $wanted) continue;

            $time = \sprintf('%02d:%02d', \intdiv($at, 60), $at % 60);
            if ($this->suits($date, $time, $partySize)) $candidates[] = $at;
        }

        // The closest one either side of the time asked for; when only one side has any, the two closest on that side
        $before = \array_filter($candidates, static fn(int $at): bool => $at < $wanted);
        $after = \array_filter($candidates, static fn(int $at): bool => $at > $wanted);
        \rsort($before);
        \sort($after);
        $nearest = [];
        if ($before !== [] && $after !== []) $nearest = [$before[0], $after[0]];
        if ($before === [] || $after === []) $nearest = \array_slice(\array_merge($before, $after), 0, self::MAX_TIMES);
        \sort($nearest);

        return \array_map(static fn(int $at): string => \sprintf('%02d:%02d', \intdiv($at, 60), $at % 60), $nearest);
    }

    private function suits(string $date, string $time, int $partySize): bool
    {
        try {
            $slot = ReservationRules::slot(config: $this->config, date: $date, time: $time, now: $this->now);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        return $this->isFree($partySize, $slot['startsAt'], $slot['endsAt']);
    }

    private function minutesOf(string $time): int
    {
        return (int) \substr($time, 0, 2) * 60 + (int) \substr($time, 3, 2);
    }

    /**
     * @param list<string> $items
     */
    private function join(array $items, string $last = 'and'): string
    {
        if (\count($items) < 2) return $items[0] ?? '';

        $final = \array_pop($items);

        return \implode(', ', $items) . ' ' . $last . ' ' . $final;
    }
}
