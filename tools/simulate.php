<?php

/**
 * Dev-only real-time simulation of a busy booking period: diners ring or use the website, tables are given and
 * confirmed, a full time is answered with the nearest free times, customers reply, and some cancel.
 * It goes through ReservationDesk, the same code the admin screen and the website form use, and sends real emails.
 * Never run this on a live site.
 *
 * Usage (inside the php container, from the site's web root):
 *   php app/services/osmium-reservations/tools/simulate.php [--every=20] [--arrivals=48] [--fresh] [--seed]
 *
 *   --every=N     seconds between arrivals (default 20)
 *   --arrivals=N  how many to make (default 48), over tomorrow and the day after
 *   --fresh       delete all reservations first
 *   --seed        make 8 tables (2, 2, 2, 4, 4, 6, 6, 8 seats) if there are none, and switch on automatic answers
 *
 * Watch it on the admin Reservations page (Live switch on). Each event is also printed here and written to
 * app/cache/reservations-sim.log.
 */

declare(strict_types=1);

if (\php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

\chdir(\dirname(__DIR__, 4));

require_once 'app/core/library/FileSystem.php';
require_once 'app/core/library/SqlLoader.php';
require_once 'app/core/library/OsmiumPDO.php';
require_once 'app/core/library/Autoload.php';

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\SqlLoader;
use Osmium\Services\OsmiumReservations\Models\NoTableException;
use Osmium\Services\OsmiumReservations\Models\Reservation;
use Osmium\Services\OsmiumReservations\Models\ReservationConfig;
use Osmium\Services\OsmiumReservations\Models\ReservationDesk;
use Osmium\Services\OsmiumReservations\Models\ReservationTable;

(new Autoload('app/config'))->register();
\spl_autoload_register(function (string $class): void {
    $prefix = 'Osmium\\Services\\OsmiumReservations\\Models\\';
    $mine = \str_starts_with($class, $prefix);
    if (!$mine) return;

    require_once __DIR__ . '/../models/' . \substr($class, \strlen($prefix)) . '.php';
});

$options = ['every' => 20, 'arrivals' => 48, 'fresh' => false, 'seed' => false];
foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--fresh') $options['fresh'] = true;
    if ($arg === '--seed') $options['seed'] = true;
    if (\preg_match('/^--(every|arrivals)=(\d+)$/', $arg, $m)) $options[$m[1]] = (int) $m[2];
}

function readConfig(string $file): object
{
    $content = (string) \file_get_contents($file);

    return \json_decode(\substr($content, (int) \strpos($content, '{')), false);
}

$dbConfig = readConfig('app/config/database/db.json.php');
$config = readConfig('app/config/config.json.php');
$config->site->FQDN = $config->site->scheme . '://' . $config->site->domain;
$db = new OsmiumPDO($dbConfig, new SqlLoader());
$osmium = (object) ['config' => $config, 'dataSource' => $db];

$desk = new ReservationDesk(osmium: $osmium);
$reservations = new Reservation($db);
$tables = new ReservationTable($db);

$logFile = 'app/cache/reservations-sim.log';
function say(string $kind, string $text): void
{
    global $logFile;
    $line = \date('H:i:s') . '  ' . \str_pad($kind, 9) . $text;
    echo $line . "\n";
    \file_put_contents($logFile, $line . "\n", FILE_APPEND);
}
\file_put_contents($logFile, '');

if ($options['fresh']) {
    $db->query('DELETE FROM ' . $db->tablePrefix() . 'reservations');
    $db->execute();
    say('SETUP', 'all reservations deleted');
}

if ($options['seed']) {
    $none = $tables->listAll() === [];
    if ($none) {
        foreach ([2, 2, 2, 4, 4, 6, 6, 8] as $n => $seats) {
            $tables->create(name: 'Table ' . ($n + 1), seats: $seats, enabled: true, sortOrder: $n + 1);
        }
        say('SETUP', '8 tables made');
    }

    ReservationConfig::save(['stepMinutes' => 15, 'minNoticeHours' => 0, 'maxDaysAhead' => 90, 'diningMinutes' => 90, 'turnaroundMinutes' => 5, 'minParty' => 1, 'maxParty' => 8, 'autoAnswer' => true, 'notifyEmails' => '']);
    say('SETUP', 'answering automatically, 90 minute meals, 5 minute turnaround');
}

$days = [(new \DateTimeImmutable('+1 day'))->format('Y-m-d'), (new \DateTimeImmutable('+2 days'))->format('Y-m-d')];
$times = ['17:30', '18:00', '18:30', '19:00', '19:00', '19:00', '19:30', '19:30', '19:30', '19:30', '20:00', '20:00', '20:00', '20:30', '20:30', '21:00', '21:30'];
$sizes = [2, 2, 2, 2, 2, 3, 4, 4, 4, 4, 5, 6, 6, 7, 8];
$names = \explode(' ', 'Adams Baker Clark Davies Ellis Foster Green Harris Ibrahim Jackson Kelly Lewis Morgan Nash Owen Price Quinn Reid Shaw Turner Usher Vance Walker Young Zane Allen Bell Cole Dean Fox Grant Hill Jones King Law Mills Page Ross Stone Wood Bishop Carter Dixon Evans Flynn Gray Hunt Ives Khan Long');
$pick = static fn(array $list): mixed => $list[\array_rand($list)];
$chance = static fn(int $percent): bool => \random_int(1, 100) <= $percent;
$short = static fn(array $r): string => $r['customer_name'] . ', ' . $r['party_size'] . ' at ' . \substr($r['starts_at'], 11, 5) . ' on ' . \substr($r['starts_at'], 8, 2) . '/' . \substr($r['starts_at'], 5, 2);

/** A caller on the phone: the host tries the time asked for; if it is full they are read the nearest free times */
$phoneCall = function (array $a) use ($desk, $chance, $pick): void {
    $input = ['name' => $a['name'], 'email' => \strtolower($a['name']) . '@example.com', 'phone' => '07700900' . \str_pad((string) \random_int(100, 999), 3, '0'), 'party_size' => $a['size'], 'table_id' => 0, 'date' => $a['date'], 'time' => $a['time'], 'notes' => ''];
    $what = $a['name'] . ', ' . $a['size'] . ' at ' . $a['time'] . ' on ' . \substr($a['date'], 8) . '/' . \substr($a['date'], 5, 2);

    try {
        $desk->createByStaff($input);
        say('PHONE', $what . ' -> booked');
    } catch (NoTableException $e) {
        if ($e->times === []) {
            say('PHONE', $what . ' -> full, nothing else that day (lost)');

            return;
        }

        if ($chance(15)) {
            say('PHONE', $what . ' -> full, offered ' . \implode(' or ', $e->times) . ', caller said no');

            return;
        }

        $time = $pick($e->times);
        $desk->createByStaff(['time' => $time] + $input);
        say('PHONE', $what . ' -> full, offered ' . \implode(' or ', $e->times) . ', caller took ' . $time . ($time < $a['time'] ? ' (earlier)' : ' (later)'));
    } catch (\InvalidArgumentException $e) {
        say('PHONE', $what . ' -> ' . $e->getMessage());
    }
};

/** A diner on the website */
$webRequest = function (array $a) use ($desk): void {
    $what = $a['name'] . ', ' . $a['size'] . ' at ' . $a['time'] . ' on ' . \substr($a['date'], 8) . '/' . \substr($a['date'], 5, 2);
    $request = ['partySize' => $a['size'], 'date' => $a['date'], 'time' => $a['time'], 'name' => $a['name'], 'email' => \strtolower($a['name']) . '@example.com', 'phone' => null, 'notes' => null, 'tableId' => null];

    try {
        say('WEB', $what . ' -> ' . $desk->acceptRequest(request: $request, now: new \DateTimeImmutable('now')));
    } catch (\InvalidArgumentException $e) {
        say('WEB', $what . ' -> refused on the page: ' . $e->getMessage());
    }
};

/** Customers who were emailed other times answer: most take one, some say no, some have not answered yet */
$replies = function () use ($reservations, $desk, $chance, $pick, $short): void {
    foreach ($reservations->listAll() as $r) {
        $waiting = $r['status'] === 'requested' && $r['table_id'] === null;
        if (!$waiting) continue;

        $roll = \random_int(1, 100);
        if ($roll > 80) continue;

        if ($roll > 65) {
            $desk->decline(id: (int) $r['id'], message: '');
            say('REPLY', $short($r) . ' -> says no thanks, declined');
            continue;
        }

        try {
            $desk->confirm(id: (int) $r['id'], tableId: 0);
            say('REPLY', $short($r) . ' -> a table had freed up, confirmed as asked');
        } catch (NoTableException $e) {
            if ($e->times === []) {
                $desk->decline(id: (int) $r['id'], message: '');
                say('REPLY', $short($r) . ' -> nothing free that day, declined');
                continue;
            }

            $time = $pick($e->times);
            $desk->reschedule(id: (int) $r['id'], input: ['party_size' => $r['party_size'], 'date' => \substr($r['starts_at'], 0, 10), 'time' => $time, 'table_id' => 0, 'admin_notes' => '']);
            $desk->confirm(id: (int) $r['id'], tableId: 0);
            say('REPLY', $short($r) . ' -> picks ' . $time . ($time < \substr($r['starts_at'], 11, 5) ? ' (earlier)' : ' (later)') . ', confirmed');
        }
    }
};

/** Someone cancels; the freed table goes to anyone still waiting for that time */
$cancellation = function () use ($reservations, $desk, $pick, $short): void {
    $open = \array_values(\array_filter($reservations->listAll(), static fn(array $r): bool => $r['status'] === 'confirmed'));
    if ($open === []) return;

    $r = $pick($open);
    $desk->cancel(id: (int) $r['id'], message: '');
    say('CANCEL', $short($r) . ' -> cancels');

    foreach ($reservations->listAll() as $w) {
        $waiting = $w['status'] === 'requested' && $w['table_id'] === null;
        if (!$waiting) continue;

        try {
            $desk->confirm(id: (int) $w['id'], tableId: 0);
            say('FREED', $short($w) . ' -> gets the freed table, confirmed');
        } catch (\InvalidArgumentException $e) {
            // Still full for them
        }
    }
};

say('START', $options['arrivals'] . ' arrivals, one every ' . $options['every'] . 's, for ' . $days[0] . ' and ' . $days[1]);
for ($i = 1; $i <= $options['arrivals']; $i++) {
    if ($i > 1) \sleep($options['every']);

    $a = ['name' => $names[($i - 1) % \count($names)] . ($i > \count($names) ? (string) $i : ''), 'size' => $pick($sizes), 'time' => $pick($times), 'date' => $pick($days)];
    try {
        $isWeb = $chance(60);
        if ($isWeb) $webRequest($a);
        if (!$isWeb) $phoneCall($a);

        $answersDue = $i % 2 === 0;
        if ($answersDue) $replies();

        $someoneCancels = $i >= 6 && $i % 4 === 0;
        if ($someoneCancels) $cancellation();
    } catch (\Throwable $e) {
        say('ERROR', $e->getMessage());
    }
}

foreach ($days as $day) {
    $counts = ['confirmed' => 0, 'requested' => 0, 'cancelled' => 0, 'declined' => 0, 'seated' => 0];
    $covers = 0;
    foreach ($reservations->listAll() as $r) {
        $isThisDay = \substr($r['starts_at'], 0, 10) === $day;
        if (!$isThisDay) continue;

        $counts[$r['status']]++;
        $holds = \in_array($r['status'], ['confirmed', 'seated'], true);
        if ($holds) $covers += (int) $r['party_size'];
    }
    say('SUMMARY', $day . ': ' . $counts['confirmed'] . ' confirmed (' . $covers . ' covers), ' . $counts['requested'] . ' still waiting, ' . $counts['cancelled'] . ' cancelled, ' . $counts['declined'] . ' declined');
}
say('DONE', 'finished');
