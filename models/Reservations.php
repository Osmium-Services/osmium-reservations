<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * What a theme calls to build its reservation form. The form posts to /api/reservations/ (fields party_size, date, time,
 * name, email, phone, notes, token and the zip honeypot) and is sent back to the page it came from.
 *
 *     $reservations = new \Osmium\Services\OsmiumReservations\Models\Reservations($this->osmium->dataSource);
 *     $rules = $reservations->rules($this->osmium->config->company->openingHours ?? null);         // hours by weekday (with optional breakStart/breakEnd), minNoticeHours, maxDaysAhead, stepMinutes, minParty, maxParty
 *     $notice = $reservations->pullNotice();   // status (sent or failed) and message, shown once
 */
class Reservations
{
    private const NOTICE_KEY = 'reservation_notice';

    public function __construct(private OsmiumPDO $database)
    {
    }

    /**
     * @param mixed $companyHours pass $this->osmium->config->company->openingHours so the hours are the ones in Company > Location
     * @return array{hours: array<int, array{open: string, close: string}|null>, minNoticeHours: int, maxDaysAhead: int, stepMinutes: int, minParty: int, maxParty: int}
     */
    public function rules(mixed $companyHours = null): array
    {
        $config = ReservationConfig::get(companyHours: $companyHours);
        unset($config['notifyEmails'], $config['diningMinutes']); // The team's addresses and the dining time are not for the page

        return $config;
    }

    /**
     * The message left for this visitor by their last reservation request, once
     *
     * @return array{status: string, message: string}|null
     */
    public function pullNotice(?array &$session = null): ?array
    {
        $session ??= $_SESSION;
        $notice = $session[self::NOTICE_KEY] ?? null;
        unset($session[self::NOTICE_KEY]);
        $this->clearSessionNotice();

        return \is_array($notice) ? $notice : null;
    }

    public static function rememberNotice(array &$session, string $status, string $message): void
    {
        $session[self::NOTICE_KEY] = ['status' => $status, 'message' => $message];
    }

    private function clearSessionNotice(): void
    {
        unset($_SESSION[self::NOTICE_KEY]);
    }
}
