<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

use Osmium\Core\Library\MailerFactory;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * Tells the customer (and the business) what happened to a reservation, and announces it to other services with a hook
 * (reservation.requested, .confirmed, .changed, .declined, .cancelled). A mail that fails to send is logged and never
 * stops the reservation; a service that fails on a hook is logged by core and skipped.
 */
class ReservationNotifier
{
    public function __construct(private object $osmium)
    {
    }

    /**
     * @param bool $tellCustomer false when the customer is told something else straight away (booked, or other times offered)
     */
    public function requested(array $reservation, bool $tellCustomer = true): void
    {
        $siteName = $this->siteName();
        if ($tellCustomer) $this->send(to: $reservation['customer_email'], mail: ReservationMail::received($reservation, $siteName));

        $adminUrl = $this->osmium->config->site->FQDN . '/' . $this->osmium->config->site->adminPath . '/reservations' . $this->slash();
        $team = ReservationMail::requestForTeam(reservation: $reservation, siteName: $siteName, adminUrl: $adminUrl);
        foreach (ReservationConfig::get()['notifyEmails'] as $email) {
            $this->send(to: $email, mail: $team);
        }

        $this->announce(event: 'reservation.requested', reservation: $reservation);
    }

    /**
     * @param list<string> $times the free times to offer, H:i
     */
    public function options(array $reservation, array $times): void
    {
        $phone = \trim((string) ($this->osmium->config->company->phone ?? ''));
        $this->send(to: $reservation['customer_email'], mail: ReservationMail::options($reservation, $this->siteName(), $times, $phone));
    }

    public function confirmed(array $reservation): void
    {
        $this->send(to: $reservation['customer_email'], mail: ReservationMail::confirmed($reservation, $this->siteName(), $this->icsUrl($reservation), $this->googleUrl($reservation)));
        $this->announce(event: 'reservation.confirmed', reservation: $reservation);
    }

    /**
     * @param bool $email tell the customer by email (the default)
     * @param bool $sms also text them, when an SMS provider is installed and the booking has a phone number
     */
    public function changed(array $reservation, bool $email = true, bool $sms = false): void
    {
        if ($email) $this->send(to: $reservation['customer_email'], mail: ReservationMail::changed($reservation, $this->siteName(), $this->icsUrl($reservation), $this->googleUrl($reservation)));
        if ($sms) $this->text(to: (string) ($reservation['customer_phone'] ?? ''), body: ReservationMail::changedText($reservation, $this->siteName()));
        $this->announce(event: 'reservation.changed', reservation: $reservation);
    }

    /** Whether a text can really be sent: an SMS provider service is installed and set up */
    public function smsReady(): bool
    {
        foreach ($this->hooks()->collect('sms.providers') as $provider) {
            $ready = \is_array($provider) && !empty($provider['ready']);
            if ($ready) return true;
        }

        return false;
    }

    public function declined(array $reservation, string $message = ''): void
    {
        $phone = \trim((string) ($this->osmium->config->company->phone ?? ''));
        $this->send(to: $reservation['customer_email'], mail: ReservationMail::declined(reservation: $reservation, siteName: $this->siteName(), message: $message, phone: $phone));
        $this->announce(event: 'reservation.declined', reservation: $reservation);
    }

    public function cancelled(array $reservation, string $message = ''): void
    {
        $phone = \trim((string) ($this->osmium->config->company->phone ?? ''));
        $this->send(to: $reservation['customer_email'], mail: ReservationMail::cancelled(reservation: $reservation, siteName: $this->siteName(), message: $message, phone: $phone));
        $this->announce(event: 'reservation.cancelled', reservation: $reservation);
    }

    public function icsUrl(array $reservation): string
    {
        return $this->osmium->config->site->FQDN . '/api/reservations-calendar' . $this->slash() . '?ref=' . \rawurlencode((string) $reservation['reference']);
    }

    private function googleUrl(array $reservation): string
    {
        $siteName = $this->siteName();

        return ReservationIcs::googleLink(
            reservation: $reservation,
            summary: ReservationMail::eventTitle($reservation, $siteName),
            description: 'Table for ' . (int) $reservation['party_size'] . '. Reference ' . $reservation['reference'],
            zone: new \DateTimeZone(\date_default_timezone_get()),
        );
    }

    /** The site's own habit for the end of a URL: the web server redirects the other form */
    private function slash(): string
    {
        return ($this->osmium->config->site->urlTrailingSlash ?? false) ? '/' : '';
    }

    private function siteName(): string
    {
        return (string) $this->osmium->config->site->name;
    }

    /**
     * @param array{subject: string, html: string} $mail
     */
    private function send(string $to, array $mail): void
    {
        $noAddress = \filter_var($to, FILTER_VALIDATE_EMAIL) === false; // A telephone customer may have given none
        if ($noAddress) return;

        try {
            $result = MailerFactory::make($this->osmium->config)->send(
                recipients: [['email' => $to]],
                subject: $mail['subject'],
                htmlBody: $mail['html'],
            );
            $failed = empty($result['success']);
            if ($failed) \error_log('Reservations: mail to ' . $to . ' failed: ' . ($result['error'] ?? 'unknown error'));
        } catch (\Throwable $e) {
            \error_log('Reservations: mail to ' . $to . ' failed: ' . $e->getMessage());
        }
    }

    private function hooks(): ServiceHooks
    {
        return new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
    }

    /** A text to the customer through whichever SMS provider is installed; a failure is logged and never stops the change */
    private function text(string $to, string $body): void
    {
        $noPhone = \trim($to) === '';
        if ($noPhone) return;

        try {
            $result = $this->hooks()->handle('sms.send', ['to' => $to, 'body' => $body]);
            $failed = \is_array($result) && empty($result['sent']);
            if ($failed) \error_log('Reservations: text to ' . $to . ' failed: ' . ($result['detail'] ?? 'unknown error'));
        } catch (\Throwable $e) {
            \error_log('Reservations: text to ' . $to . ' failed: ' . $e->getMessage());
        }
    }

    private function announce(string $event, array $reservation): void
    {
        $hooks = $this->hooks();
        $hooks->dispatch($event, ['reservation' => $reservation, 'reservationId' => (int) $reservation['id'], 'dataSource' => $this->osmium->dataSource, 'osmium' => $this->osmium]);
    }
}
