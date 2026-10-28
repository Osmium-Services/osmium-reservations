<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * The words of every email a reservation sends. Free of the database and the mailer, so they can be tested on their own.
 * Each method returns the subject and the HTML body.
 */
class ReservationMail
{
    /**
     * @param array<string, mixed> $reservation A reservation row with party_size
     * @return array{subject: string, html: string}
     */
    public static function received(array $reservation, string $siteName): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'We have your table request',
            heading: 'Thanks, ' . $reservation['customer_name'],
            paragraphs: [
                'We have received your request and will confirm it shortly. Nothing is booked until you hear from us.',
                self::details($reservation),
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function confirmed(array $reservation, string $siteName, string $icsUrl, string $googleUrl): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your table is booked',
            heading: 'See you soon, ' . $reservation['customer_name'],
            paragraphs: [
                self::details($reservation),
                '<p>Add it to your calendar: <a href="' . self::e($googleUrl) . '">Google Calendar</a> or <a href="' . self::e($icsUrl) . '">any other calendar (.ics file)</a>.</p>',
                'If you need to change or cancel, just reply to this email.',
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function changed(array $reservation, string $siteName, string $icsUrl, string $googleUrl): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your reservation has changed',
            heading: 'Your reservation has been updated',
            paragraphs: [
                self::details($reservation),
                '<p>Please update your calendar: <a href="' . self::e($googleUrl) . '">Google Calendar</a> or <a href="' . self::e($icsUrl) . '">any other calendar (.ics file)</a>. Remove the earlier entry if your calendar keeps both.</p>',
                'If this does not suit you, just reply to this email.',
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function declined(array $reservation, string $siteName, string $message = '', string $phone = ''): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'About your reservation request',
            heading: 'Sorry, ' . $reservation['customer_name'],
            paragraphs: \array_values(\array_filter([
                'We are not able to take this reservation:',
                self::details($reservation),
                $message !== '' ? '<p style="white-space:pre-line">' . self::e($message) . '</p>' : '',
                $phone !== '' ? 'To arrange another time, please call us on ' . $phone . ' or send a new request on our website.' : 'To arrange another time, please send a new request on our website.',
            ], static fn(string $paragraph): bool => $paragraph !== '')),
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function cancelled(array $reservation, string $siteName, string $message = '', string $phone = ''): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your reservation is cancelled',
            heading: 'Your reservation is cancelled',
            paragraphs: \array_values(\array_filter([
                self::details($reservation),
                $message !== '' ? '<p style="white-space:pre-line">' . self::e($message) . '</p>' : '',
                $phone !== '' ? 'To arrange another time, please call us on ' . $phone . ' or send a new request on our website.' : 'To arrange another time, please send a new request on our website.',
            ], static fn(string $paragraph): bool => $paragraph !== '')),
        );
    }

    /**
     * The note to the business when a request arrives
     *
     * @return array{subject: string, html: string}
     */
    public static function requestForTeam(array $reservation, string $siteName, string $adminUrl): array
    {
        $contact = self::e((string) $reservation['customer_name']) . ', ' . self::e((string) $reservation['customer_email'])
            . ($reservation['customer_phone'] ? ', ' . self::e((string) $reservation['customer_phone']) : '');
        $notes = !empty($reservation['customer_notes']) ? '<p><strong>Their message:</strong><br>' . \nl2br(self::e((string) $reservation['customer_notes'])) . '</p>' : '';

        return self::compose(
            siteName: $siteName,
            subject: 'New reservation request from ' . $reservation['customer_name'],
            heading: 'New reservation request',
            paragraphs: [
                self::details($reservation),
                '<p><strong>From:</strong> ' . $contact . '</p>' . $notes,
                '<p><a href="' . self::e($adminUrl) . '">Confirm or decline it in the admin</a></p>',
            ],
        );
    }

    /** The one-line summary used as a calendar event's title */
    public static function eventTitle(array $reservation, string $siteName): string
    {
        return 'Table at ' . $siteName;
    }

    /** "Friday 6 November 2026 at 19:30": the time the table is free again is never shown */
    public static function when(array $reservation): string
    {
        return (new \DateTimeImmutable($reservation['starts_at']))->format('l j F Y \a\t H:i');
    }

    private static function details(array $reservation): string
    {
        $people = (int) $reservation['party_size'];
        $rows = [
            'When' => self::when($reservation),
            'Party' => $people . ($people === 1 ? ' person' : ' people'),
            'Reference' => (string) $reservation['reference'],
        ];

        $html = '<table cellpadding="4" style="border-collapse:collapse">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td><strong>' . $label . '</strong></td><td>' . self::e($value) . '</td></tr>';
        }

        return $html . '</table>';
    }

    /**
     * @param list<string> $paragraphs
     * @return array{subject: string, html: string}
     */
    private static function compose(string $siteName, string $subject, string $heading, array $paragraphs): array
    {
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $isMarkup = \str_starts_with($paragraph, '<');
            $body .= $isMarkup ? $paragraph : '<p>' . self::e($paragraph) . '</p>';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;max-width:560px">'
            . '<h2 style="margin:0 0 12px">' . self::e($heading) . '</h2>' . $body
            . '<p style="color:#777;margin-top:24px">' . self::e($siteName) . '</p></div>';

        return ['subject' => $subject, 'html' => $html];
    }

    private static function e(string $text): string
    {
        return \htmlspecialchars($text, ENT_QUOTES);
    }
}
