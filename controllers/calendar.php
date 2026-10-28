<?php

use Osmium\Services\OsmiumReservations\Models\Reservation;
use Osmium\Services\OsmiumReservations\Models\ReservationIcs;
use Osmium\Services\OsmiumReservations\Models\ReservationMail;

/**
 * Calendar file for a reservation (GET /api/reservations-calendar/?ref=REFERENCE). The reference is the key: ten characters
 * chosen at random, only in the customer's emails. The file holds the event, the time and the reference, never the
 * customer's details. A requested or declined reservation is not an event, so it answers 404.
 */

$reference = \preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['ref'] ?? ''));
$reservation = $reference !== '' ? (new Reservation($this->osmium->dataSource))->getByReference($reference) : null;

$isEvent = $reservation !== null && \in_array($reservation['status'], ['confirmed', 'seated', 'cancelled'], true);
if (!$isEvent) {
    \http_response_code(404);
    echo 'Not found';
    exit;
}

$siteName = (string) $this->osmium->config->site->name;
$host = (string) \parse_url($this->osmium->config->site->FQDN, PHP_URL_HOST);
$stamp = new \DateTimeImmutable('now');

$file = ReservationIcs::build(
    reservation: $reservation,
    summary: ReservationMail::eventTitle($reservation, $siteName),
    description: 'Table for ' . (int) $reservation['party_size'] . '. Reference ' . $reservation['reference'],
    host: $host,
    stamp: $stamp,
);

\header('Content-Type: text/calendar; charset=utf-8');
\header('Content-Disposition: attachment; filename="reservation-' . $reservation['reference'] . '.ics"');
\header('X-Robots-Tag: noindex');
echo $file;
exit;
