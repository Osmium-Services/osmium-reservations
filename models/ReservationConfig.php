<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * The site-wide reservation rules. Opening hours are not kept here: they come from Company > Location
 * (company.openingHours). This service's own settings file (app/config/services/osmium-reservations.json.php) holds an
 * optional break for each weekday (such as lunch), the notice a request needs, how far ahead it may be, the step times
 * snap to, an optional narrower bookable window for each weekday, and who is emailed when a request comes in.
 */
class ReservationConfig
{
    public const PATH = 'app/config/services/osmium-reservations.json.php';
    public const STEPS = [5, 10, 15, 20, 30, 60];
    public const DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @return array{breaks: array<int, array{start: string, end: string}|null>, minNoticeHours: int, maxDaysAhead: int, stepMinutes: int, notifyEmails: list<string>}
     */
    public static function defaults(): array
    {
        return [
            'windows' => [1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null, 7 => null],
            'breaks' => [1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null, 7 => null],
            'minNoticeHours' => 2,
            'maxDaysAhead' => 90,
            'stepMinutes' => 15,
            'diningMinutes' => 90,
            'minParty' => 1,
            'maxParty' => 8,
            'notifyEmails' => [],
        ];
    }

    /**
     * The rules with 'hours' added: for each weekday (1 = Monday to 7 = Sunday) null when closed, otherwise
     * open/close from the company's opening hours plus the break, if any.
     *
     * @param mixed $companyHours config company.openingHours (array or decoded object)
     * @return array{hours: array<int, array{open: string, close: string, breakStart: ?string, breakEnd: ?string}|null>, breaks: array, minNoticeHours: int, maxDaysAhead: int, stepMinutes: int, notifyEmails: list<string>}
     */
    public static function get(string $path = self::PATH, mixed $companyHours = null): array
    {
        $rules = self::stored($path);
        $rules['hours'] = self::hoursFor(companyHours: $companyHours, breaks: $rules['breaks'], windows: $rules['windows']);

        return $rules;
    }

    /**
     * @return array{breaks: array, minNoticeHours: int, maxDaysAhead: int, stepMinutes: int, notifyEmails: list<string>}
     */
    public static function stored(string $path = self::PATH): array
    {
        $defaults = self::defaults();

        $fileExists = \file_exists($path);
        if (!$fileExists) return $defaults;

        $content = (string) \file_get_contents($path);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) return $defaults;

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true);
        $saved = \is_array($decoded) ? ($decoded['reservations'] ?? []) : [];
        if (!\is_array($saved)) return $defaults;

        $breaks = $defaults['breaks'];
        $windows = $defaults['windows'];
        foreach ($breaks as $day => $unused) {
            $breaks[$day] = self::cleanPair($saved['breaks'][$day] ?? $saved['breaks'][(string) $day] ?? null);
            $windows[$day] = self::cleanPair($saved['windows'][$day] ?? $saved['windows'][(string) $day] ?? null);
        }

        $step = (int) ($saved['stepMinutes'] ?? 0);

        return [
            'windows' => $windows,
            'breaks' => $breaks,
            'minNoticeHours' => \max(0, \min(720, (int) ($saved['minNoticeHours'] ?? $defaults['minNoticeHours']))),
            'maxDaysAhead' => \max(1, \min(730, (int) ($saved['maxDaysAhead'] ?? $defaults['maxDaysAhead']))),
            'stepMinutes' => \in_array($step, self::STEPS, true) ? $step : $defaults['stepMinutes'],
            'diningMinutes' => \max(15, \min(480, (int) ($saved['diningMinutes'] ?? $defaults['diningMinutes']))),
            'minParty' => \max(1, \min(100, (int) ($saved['minParty'] ?? $defaults['minParty']))),
            'maxParty' => \max(1, \min(100, (int) ($saved['maxParty'] ?? $defaults['maxParty']))),
            'notifyEmails' => self::cleanEmails($saved['notifyEmails'] ?? []),
        ];
    }

    /**
     * Each day's bookable times: the company's opening hours, narrowed by that day's own window if it has one (a window
     * can never reach outside the opening hours), plus the break.
     *
     * @param array<int, array{start: string, end: string}|null> $breaks
     * @param array<int, array{start: string, end: string}|null> $windows
     * @return array<int, array{open: string, close: string, breakStart: ?string, breakEnd: ?string}|null>
     */
    public static function hoursFor(mixed $companyHours, array $breaks, array $windows = []): array
    {
        $company = \json_decode(\json_encode($companyHours) ?: '[]', associative: true);
        $company = \is_array($company) ? $company : [];

        $hours = [];
        foreach (self::DAY_NAMES as $day => $name) {
            $row = $company[$name] ?? null;
            $isOpen = \is_array($row) && empty($row['closed']) && self::isTime((string) ($row['opens'] ?? '')) && self::isTime((string) ($row['closes'] ?? '')) && $row['closes'] > $row['opens'];
            $break = $breaks[$day] ?? null;
            $window = $windows[$day] ?? null;
            $open = $isOpen ? \max($row['opens'], $window['start'] ?? $row['opens']) : '';
            $close = $isOpen ? \min($row['closes'], $window['end'] ?? $row['closes']) : '';
            $windowEmpty = $isOpen && $close <= $open;
            $hours[$day] = $isOpen && !$windowEmpty ? ['open' => $open, 'close' => $close, 'breakStart' => $break['start'] ?? null, 'breakEnd' => $break['end'] ?? null] : null;
        }

        return $hours;
    }

    /**
     * Saves the rules; throws if any of them is not valid
     *
     * @throws \InvalidArgumentException
     */
    public static function save(array $settings, string $path = self::PATH): array
    {
        $clean = self::validate($settings);

        $dir = \dirname($path);
        $dirMissing = !\is_dir($dir);
        if ($dirMissing) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(value: ['reservations' => $clean], flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        \file_put_contents($path, "<?php exit(); ?>\n" . $json . "\n");

        return $clean;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function validate(array $settings): array
    {
        $breaks = [];
        $windows = [];
        for ($day = 1; $day <= 7; $day++) {
            $breaks[$day] = self::validPair(row: $settings['breaks'][$day] ?? $settings['breaks'][(string) $day] ?? [], message: 'A break needs a start time earlier than its end time');
            $windows[$day] = self::validPair(row: $settings['windows'][$day] ?? $settings['windows'][(string) $day] ?? [], message: 'Bookable times need a start earlier than their end');
        }

        $step = (int) ($settings['stepMinutes'] ?? 0);
        $stepBad = !\in_array($step, self::STEPS, true);
        if ($stepBad) throw new \InvalidArgumentException('Choose a time step from the list');

        $notice = (int) ($settings['minNoticeHours'] ?? -1);
        $noticeBad = $notice < 0 || $notice > 720;
        if ($noticeBad) throw new \InvalidArgumentException('Minimum notice must be between 0 and 720 hours');

        $ahead = (int) ($settings['maxDaysAhead'] ?? 0);
        $aheadBad = $ahead < 1 || $ahead > 730;
        if ($aheadBad) throw new \InvalidArgumentException('How far ahead must be between 1 and 730 days');

        $dining = (int) ($settings['diningMinutes'] ?? 0);
        $diningBad = $dining < 15 || $dining > 480;
        if ($diningBad) throw new \InvalidArgumentException('Dining time must be between 15 and 480 minutes');

        $minParty = (int) ($settings['minParty'] ?? 0);
        $maxParty = (int) ($settings['maxParty'] ?? 0);
        $partyBad = $minParty < 1 || $maxParty < $minParty || $maxParty > 100;
        if ($partyBad) throw new \InvalidArgumentException('Party sizes must run from at least 1 up to 100, with the largest not below the smallest');

        $emails = self::splitEmails($settings['notifyEmails'] ?? '');
        $emailBad = $emails['rejected'] !== [];
        if ($emailBad) throw new \InvalidArgumentException('These are not valid email addresses: ' . \implode(', ', $emails['rejected']));

        return ['windows' => $windows, 'breaks' => $breaks, 'minNoticeHours' => $notice, 'maxDaysAhead' => $ahead, 'stepMinutes' => $step, 'diningMinutes' => $dining, 'minParty' => $minParty, 'maxParty' => $maxParty, 'notifyEmails' => $emails['valid']];
    }

    public static function isTime(string $value): bool
    {
        return (bool) \preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    /**
     * @return array{valid: list<string>, rejected: list<string>}
     */
    public static function splitEmails(array|string $input): array
    {
        $parts = \is_array($input) ? $input : \preg_split('/[\s,;]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
        $valid = [];
        $rejected = [];
        foreach ($parts as $part) {
            $email = \trim((string) $part);
            $isEmail = \filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            if ($isEmail) $valid[] = $email;
            if (!$isEmail && $email !== '') $rejected[] = $email;
        }

        return ['valid' => \array_values(\array_unique($valid)), 'rejected' => $rejected];
    }

    private static function cleanEmails(mixed $emails): array
    {
        $isList = \is_array($emails);

        return $isList ? self::splitEmails($emails)['valid'] : [];
    }

    /** A start/end pair from the form: null when both are empty, otherwise both must be times with the end later */
    public static function validPair(mixed $row, string $message): ?array
    {
        $start = (string) ($row['start'] ?? '');
        $end = (string) ($row['end'] ?? '');
        $isSet = $start !== '' || $end !== '';
        $bad = $isSet && (!self::isTime($start) || !self::isTime($end) || $end <= $start);
        if ($bad) throw new \InvalidArgumentException($message);

        return $isSet ? ['start' => $start, 'end' => $end] : null;
    }

    private static function cleanPair(mixed $saved): ?array
    {
        $good = \is_array($saved) && self::isTime((string) ($saved['start'] ?? '')) && self::isTime((string) ($saved['end'] ?? '')) && $saved['end'] > $saved['start'];

        return $good ? ['start' => $saved['start'], 'end' => $saved['end']] : null;
    }
}
