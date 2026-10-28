<?php

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Services\OsmiumReservations\Models\ReservationRequest;
use Osmium\Services\OsmiumReservations\Models\Reservations;

/**
 * Table request endpoint (POST /api/reservations). Fields: party_size, date (Y-m-d), time (H:i), name, email, phone
 * (optional), notes (optional), plus the form's token and the zip honeypot, and the answer of any bot protection
 * installed (form.verify). The visitor is always sent back to the page they came from, with a one-time notice for the
 * theme to show.
 */

$sg = $this->osmium->superGlobal;

$requestMethod = $sg->get(type: 'SERVER', name: 'REQUEST_METHOD');
$notPost = $requestMethod !== 'POST';
if ($notPost) {
    $this->osmium->header->redirect(targetURL: '/', statusCode: 302);
}

$referrer = $_SERVER['HTTP_REFERER'] ?? '/';
$back = \parse_url(url: $referrer, component: PHP_URL_PATH) ?: '/'; // Only the path: never send a visitor to another site
$notice = fn(string $status, string $message) => Reservations::rememberNotice(session: $_SESSION, status: $status, message: $message);

$honeyPot = $sg->get(type: 'POST', name: 'zip');
$botDetected = !empty($honeyPot);

$submittedToken = $sg->get(type: 'POST', name: 'token');
$sessionToken = $sg->get(type: 'SESSION', name: 'token');
$tokenIsValid = !empty($submittedToken)
    && !empty($sessionToken)
    && \hash_equals(known_string: $sessionToken, user_string: $submittedToken);

$tokenIssuedAt = $sg->get(type: 'SESSION', name: 'token_issued_at');
$secondsSinceTokenIssued = $tokenIssuedAt ? (\time() - (int) $tokenIssuedAt) : 0;
$submittedTooFast = $secondsSinceTokenIssued < 4;

unset($_SESSION['token'], $_SESSION['token_issued_at']); // One token, one submission

// Any installed bot protection answers the form.verify hook; this service never names one
$protectionAnswers = (new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource))))
    ->collect('form.verify', ['form' => 'reservation', 'post' => $_POST, 'dataSource' => $this->osmium->dataSource]);
$protectionFailed = \in_array(false, $protectionAnswers, true);

$looksLikeABot = $botDetected || !$tokenIsValid || $submittedTooFast || $protectionFailed;
if ($looksLikeABot) {
    $notice('failed', ReservationRequest::TECHNICAL_PROBLEM); // Never say which check failed
    $this->osmium->header->redirect(targetURL: $back, statusCode: 302);
}

try {
    $request = ReservationRequest::parse($_POST);
    $desk = new ReservationDesk(osmium: $this->osmium);
    $notice('sent', $desk->acceptRequest(request: $request, now: new \DateTimeImmutable('now')));
} catch (\InvalidArgumentException $e) {
    $notice('failed', $e->getMessage()); // These messages are written for the customer
} catch (\Throwable $e) {
    \error_log('Reservations: request failed: ' . $e->getMessage());
    $notice('failed', ReservationRequest::TECHNICAL_PROBLEM);
}

$this->osmium->header->redirect(targetURL: $back, statusCode: 302);
