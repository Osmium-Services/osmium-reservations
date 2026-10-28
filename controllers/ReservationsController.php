<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\OsmiumReservations\Models\Reservation;
use Osmium\Services\OsmiumReservations\Models\ReservationConfig;
use Osmium\Services\OsmiumReservations\Models\ReservationNotifier;
use Osmium\Services\OsmiumReservations\Models\ReservationRules;
use Osmium\Services\OsmiumReservations\Models\ReservationTable;

/**
 * Reservations controller - the requests, the tables, and the booking rules.
 *
 * Routes:
 *   - index()  → /admin/reservations/
 *   - action() → /admin/reservations/action/   (AJAX)
 */
class ReservationsController extends AdminController
{
    private const RECORD_TYPE = 'reservation';

    // =========================================================================
    // Public Actions
    // =========================================================================

    public function index(): void
    {
        $db = $this->osmium->dataSource;
        $reservations = new Reservation($db);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/reservations.php';
        $this->data['admin']['reservations'] = \array_map($this->formatReservation(...), $reservations->listAll());
        $this->data['admin']['deletedReservations'] = \array_map($this->formatDeleted(...), $reservations->listDeleted());
        $this->data['admin']['tables'] = \array_map($this->formatTable(...), (new ReservationTable($db))->listAll());
        $this->loadRules();

        $this->setView('reservations/index.phtml');
    }

    /**
     * The setup page (Services > Osmium Reservations): the tables and the booking rules
     */
    public function settings(): void
    {
        $db = $this->osmium->dataSource;

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/reservations.php';
        $this->data['admin']['tables'] = \array_map($this->formatTable(...), (new ReservationTable($db))->listAll());
        $this->loadRules();
        $this->data['admin']['steps'] = ReservationConfig::STEPS;
        $this->data['admin']['days'] = ReservationConfig::DAY_NAMES;

        $this->setView('reservations/settings.phtml');
    }

    private function loadRules(): void
    {
        $companyHours = $this->osmium->config->company->openingHours ?? null;
        $this->data['admin']['settings'] = ReservationConfig::get(companyHours: $companyHours);
        $this->data['admin']['companyHours'] = ReservationConfig::hoursFor(companyHours: $companyHours, breaks: []);
        $this->data['admin']['settings']['notifyEmailsText'] = \implode("\n", $this->data['admin']['settings']['notifyEmails']);
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';
        $id = (int) ($input['id'] ?? 0);

        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $needsId = \in_array($action, ['confirm', 'decline', 'cancel', 'reschedule', 'delete', 'restore', 'permanently_delete', 'delete_table'], true);
        $missingParams = !$action || ($needsId && !$id);
        if ($missingParams) $this->admin->jsonError('Missing parameters');

        try {
            $result = match ($action) {
                'confirm' => $this->confirm(id: $id, tableId: (int) ($input['table_id'] ?? 0)),
                'decline' => $this->decline(id: $id, message: \trim((string) ($input['message'] ?? ''))),
                'cancel' => $this->cancel(id: $id, message: \trim((string) ($input['message'] ?? ''))),
                'create' => $this->createByStaff($input),
                'reschedule' => $this->reschedule(id: $id, input: $input),
                'delete' => $this->deleteReservation($id),
                'restore' => $this->restoreReservation($id),
                'permanently_delete' => $this->permanentlyDelete($id),
                'purge_deleted' => $this->purgeDeleted(),
                'save_table' => $this->saveTable($input),
                'delete_table' => $this->deleteTable($id),
                'save_settings' => $this->saveSettings($input),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Reservations
    // =========================================================================

    /** Confirming gives the reservation a table: the one chosen, or else the smallest free one that fits */
    private function confirm(int $id, int $tableId): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'confirmed');
        $table = $this->tableFor(model: $model, reservation: $reservation, tableId: $tableId, partySize: (int) $reservation['party_size'], startsAt: $reservation['starts_at'], endsAt: $reservation['ends_at']);

        $model->setTable(id: $id, tableId: (int) $table['id']);
        $model->setStatus(id: $id, status: 'confirmed', sequence: (int) $reservation['ics_sequence']);
        $this->logChange(action: 'Confirmed', id: $id, name: $reservation['customer_name']);
        (new ReservationNotifier($this->osmium))->confirmed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function decline(int $id, string $message): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'declined');

        $model->setStatus(id: $id, status: 'declined', sequence: (int) $reservation['ics_sequence']);
        $this->logChange(action: 'Declined', id: $id, name: $reservation['customer_name']);
        (new ReservationNotifier($this->osmium))->declined(reservation: $model->get($id), message: \mb_substr($message, 0, 1000));

        return ['success' => true, 'id' => $id];
    }

    private function cancel(int $id, string $message): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $this->findOpen(model: $model, id: $id, allowed: ['requested', 'confirmed'], verb: 'cancelled');

        $model->setStatus(id: $id, status: 'cancelled', sequence: (int) $reservation['ics_sequence'] + 1);
        $this->logChange(action: 'Cancelled', id: $id, name: $reservation['customer_name']);
        (new ReservationNotifier($this->osmium))->cancelled(reservation: $model->get($id), message: \mb_substr($message, 0, 1000));

        return ['success' => true, 'id' => $id];
    }

    /**
     * A reservation made for a customer who rang or called in: confirmed at once (and emailed, when there is an
     * address). Opening hours, breaks and clashes still apply; the notice and how-far-ahead limits are for the website.
     */
    private function createByStaff(array $input): array
    {
        $db = $this->osmium->dataSource;

        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Enter the customer\'s name (up to 100 characters)');

        $email = \trim((string) ($input['email'] ?? ''));
        $phone = \trim((string) ($input['phone'] ?? ''));
        $emailBad = $email !== '' && (\filter_var($email, FILTER_VALIDATE_EMAIL) === false || \strlen($email) > 150);
        if ($emailBad) throw new \InvalidArgumentException('That email address is not valid');

        $noContact = $email === '' && $phone === '';
        if ($noContact) throw new \InvalidArgumentException('Enter a phone number or an email address');
        if (\strlen($phone) > 30) throw new \InvalidArgumentException('Phone number is limited to 30 characters');

        $partySize = $this->validPartySize($input);
        $start = $this->validStart($input);
        $config = ReservationConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null);
        $slot = ReservationRules::staffSlot(config: $config, start: $start);

        $model = new Reservation($db);
        $table = $this->tableFor(model: $model, reservation: null, tableId: (int) ($input['table_id'] ?? 0), partySize: $partySize, startsAt: $slot['startsAt'], endsAt: $slot['endsAt']);

        $notes = \trim((string) ($input['notes'] ?? ''));
        $created = $model->create(
            request: ['partySize' => $partySize, 'tableId' => (int) $table['id'], 'name' => $name, 'email' => $email, 'phone' => $phone !== '' ? $phone : null, 'notes' => $notes !== '' ? $notes : null],
            startsAt: $slot['startsAt'],
            endsAt: $slot['endsAt'],
        );
        $model->setStatus(id: $created['id'], status: 'confirmed', sequence: 0);
        $this->logChange(action: 'Created by staff', id: $created['id'], name: $name);
        (new ReservationNotifier($this->osmium))->confirmed($model->get($created['id']));

        return ['success' => true, 'id' => $created['id']];
    }

    private function reschedule(int $id, array $input): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $model->get($id);
        $missing = $reservation === null || $reservation['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Reservation not found');

        $partySize = $this->validPartySize($input);
        $start = $this->validStart($input);
        $config = ReservationConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null);

        $startsAt = $start->format('Y-m-d H:i:s');
        $endsAt = $start->modify('+' . $config['diningMinutes'] . ' minutes')->format('Y-m-d H:i:s');
        $chosenId = (int) ($input['table_id'] ?? 0);
        $currentTableId = $reservation['table_id'] !== null ? (int) $reservation['table_id'] : 0;

        $timeChanged = $startsAt !== $reservation['starts_at'] || $partySize !== (int) $reservation['party_size'];
        $changed = $timeChanged || $chosenId !== $currentTableId;
        if ($changed) ReservationRules::staffSlot(config: $config, start: $start);

        $isConfirmed = $reservation['status'] === 'confirmed';
        $needsTable = $isConfirmed || $chosenId > 0;
        $table = $needsTable ? $this->tableFor(model: $model, reservation: $reservation, tableId: $chosenId, partySize: $partySize, startsAt: $startsAt, endsAt: $endsAt) : null;

        $sequence = (int) $reservation['ics_sequence'] + ($isConfirmed && $timeChanged ? 1 : 0);
        $notes = \trim((string) ($input['admin_notes'] ?? ''));

        $model->reschedule(id: $id, partySize: $partySize, tableId: $table !== null ? (int) $table['id'] : null, startsAt: $startsAt, endsAt: $endsAt, adminNotes: $notes !== '' ? $notes : null, sequence: $sequence);
        $this->logChange(action: 'Updated', id: $id, name: $reservation['customer_name']);

        $tellCustomer = $isConfirmed && $timeChanged;
        if ($tellCustomer) (new ReservationNotifier($this->osmium))->changed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function deleteReservation(int $id): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $model->get($id);
        $missing = $reservation === null || $reservation['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Reservation not found');

        $model->softDelete($id);
        $this->logChange(action: 'Deleted', id: $id, name: $reservation['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    private function restoreReservation(int $id): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $model->get($id);
        if (!$reservation) throw new \InvalidArgumentException('Reservation not found');

        $model->restore($id);
        $this->logChange(action: 'Restored', id: $id, name: $reservation['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    private function permanentlyDelete(int $id): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $model = new Reservation($this->osmium->dataSource);
        $reservation = $model->get($id);
        if (!$reservation) throw new \InvalidArgumentException('Reservation not found');

        $this->logChange(action: 'Permanently deleted', id: $id, name: $reservation['customer_name']);
        $model->hardDelete($id);

        return ['success' => true, 'id' => $id];
    }

    private function purgeDeleted(): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $count = (new Reservation($this->osmium->dataSource))->purgeDeleted();
        $this->admin->model->changelog->log(description: "Purged {$count} deleted reservation(s)", recordType: self::RECORD_TYPE, recordId: 0);

        return ['success' => true, 'count' => $count];
    }

    /**
     * @param list<string> $allowed
     */
    private function findOpen(Reservation $model, int $id, array $allowed, string $verb): array
    {
        $reservation = $model->get($id);
        $missing = $reservation === null || $reservation['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Reservation not found');

        $wrongState = !\in_array($reservation['status'], $allowed, true);
        if ($wrongState) throw new \InvalidArgumentException('This reservation is ' . $reservation['status'] . ' and cannot be ' . $verb);

        return $reservation;
    }

    private function validPartySize(array $input): int
    {
        $partySize = (int) ($input['party_size'] ?? 0);
        $bad = $partySize < 1 || $partySize > 100;
        if ($bad) throw new \InvalidArgumentException('Enter how many people (1 to 100)');

        return $partySize;
    }

    private function validStart(array $input): \DateTimeImmutable
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', \trim((string) ($input['date'] ?? '')) . ' ' . \trim((string) ($input['time'] ?? '')));
        if ($start === false) throw new \InvalidArgumentException('Enter a real date and time');

        return $start;
    }

    /**
     * The table for a reservation at this time: the one chosen (it must have the seats and nothing confirmed then), or
     * else the smallest free one that fits.
     *
     * @param array<string, mixed>|null $reservation the reservation being moved, which never clashes with itself
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    private function tableFor(Reservation $model, ?array $reservation, int $tableId, int $partySize, string $startsAt, string $endsAt): array
    {
        $excludeId = $reservation !== null ? (int) $reservation['id'] : 0;

        if ($tableId < 1) {
            $free = $model->findFreeTable(partySize: $partySize, startsAt: $startsAt, endsAt: $endsAt, excludeId: $excludeId);
            if ($free === null) throw new \InvalidArgumentException('No table for ' . $partySize . ' is free at ' . (new \DateTimeImmutable($startsAt))->format('H:i \o\n D j M') . '. Change the time or party size first.');

            return $free;
        }

        $table = (new ReservationTable($this->osmium->dataSource))->get($tableId);
        $unusable = $table === null || !$table['enabled'];
        if ($unusable) throw new \InvalidArgumentException('Choose a table that is switched on');

        $tooSmall = (int) $table['seats'] < $partySize;
        if ($tooSmall) throw new \InvalidArgumentException($table['name'] . ' seats ' . (int) $table['seats'] . ', so it is too small for ' . $partySize . '. Choose another table.');

        $clash = $model->findOverlap(excludeId: $excludeId, tableId: $tableId, startsAt: $startsAt, endsAt: $endsAt);
        if ($clash !== null) {
            $when = (new \DateTimeImmutable($clash['starts_at']))->format('D j M, H:i');
            throw new \InvalidArgumentException($table['name'] . ' is already taken by the confirmed reservation for ' . $clash['customer_name'] . ' (' . $when . '). Choose another table or change the time.');
        }

        return $table;
    }

    // =========================================================================
    // Tables and settings
    // =========================================================================

    private function saveTable(array $input): array
    {
        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Name is required and limited to 100 characters');

        $seats = (int) ($input['seats'] ?? 0);
        $seatsBad = $seats < 1 || $seats > 100;
        if ($seatsBad) throw new \InvalidArgumentException('Seats must be between 1 and 100');

        $model = new ReservationTable($this->osmium->dataSource);
        $id = (int) ($input['id'] ?? 0);
        $enabled = !empty($input['enabled']);
        $sortOrder = \max(0, (int) ($input['sort_order'] ?? 0));

        $isNew = $id === 0;
        if (!$isNew && $model->get($id) === null) throw new \InvalidArgumentException('Not found');

        $savedId = $isNew ? $model->create(name: $name, seats: $seats, enabled: $enabled, sortOrder: $sortOrder) : $id;
        if (!$isNew) $model->update(id: $id, name: $name, seats: $seats, enabled: $enabled, sortOrder: $sortOrder);
        $this->admin->model->changelog->log(description: ($isNew ? 'Created' : 'Updated') . ' reservation table: ' . $name, recordType: 'reservation_table', recordId: $savedId);

        return ['success' => true, 'id' => $savedId];
    }

    private function deleteTable(int $id): array
    {
        $model = new ReservationTable($this->osmium->dataSource);
        $table = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => (int) $row['id'] === $id))[0] ?? null;
        if ($table === null) throw new \InvalidArgumentException('Not found');

        $inUse = (int) $table['reservation_count'] > 0;
        if ($inUse) throw new \InvalidArgumentException('Reservations use this table, so it cannot be deleted. Turn it off instead.');

        $model->delete($id);
        $this->admin->model->changelog->log(description: 'Deleted reservation table: ' . $table['name'], recordType: 'reservation_table', recordId: $id);

        return ['success' => true, 'id' => $id];
    }

    private function saveSettings(array $input): array
    {
        $breaks = [];
        $windows = [];
        for ($day = 1; $day <= 7; $day++) {
            $windows[$day] = [
                'start' => (string) ($input["window_start_{$day}"] ?? ''),
                'end' => (string) ($input["window_end_{$day}"] ?? ''),
            ];
            $breaks[$day] = [
                'start' => (string) ($input["break_start_{$day}"] ?? ''),
                'end' => (string) ($input["break_end_{$day}"] ?? ''),
            ];
        }

        ReservationConfig::save([
            'windows' => $windows,
            'breaks' => $breaks,
            'stepMinutes' => $input['step_minutes'] ?? 0,
            'diningMinutes' => $input['dining_minutes'] ?? 0,
            'minParty' => $input['min_party'] ?? 0,
            'maxParty' => $input['max_party'] ?? 0,
            'minNoticeHours' => $input['min_notice_hours'] ?? -1,
            'maxDaysAhead' => $input['max_days_ahead'] ?? 0,
            'notifyEmails' => (string) ($input['notify_emails'] ?? ''),
        ]);
        $this->admin->model->changelog->log(description: 'Updated reservation settings', recordType: 'settings', recordId: 0);

        return ['success' => true];
    }

    // =========================================================================
    // Formatting
    // =========================================================================

    private function formatReservation(array $row): array
    {
        $start = new \DateTimeImmutable($row['starts_at']);

        return [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'statusBadge' => $this->statusBadge($row['status']),
            'whenText' => $start->format('D j M Y, H:i'),
            'customerHtml' => '<strong>' . \htmlspecialchars((string) $row['customer_name']) . '</strong><br><small>' . \htmlspecialchars((string) $row['customer_email']) . ($row['customer_phone'] ? ' &middot; ' . \htmlspecialchars((string) $row['customer_phone']) : '') . '</small>',
            'partySize' => (int) $row['party_size'],
            'tableName' => $row['table_name'] ?? '',
            'tableId' => $row['table_id'] !== null ? (int) $row['table_id'] : '',
            'customerName' => (string) $row['customer_name'],
            'customerEmail' => (string) $row['customer_email'],
            'customerPhone' => (string) ($row['customer_phone'] ?? ''),
            'endTime' => (new \DateTimeImmutable($row['ends_at']))->format('H:i'),
            'date' => $start->format('Y-m-d'),
            'time' => $start->format('H:i'),
            'customerNotes' => $row['customer_notes'] ?? '',
            'adminNotes' => $row['admin_notes'] ?? '',
        ];
    }

    private function formatDeleted(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['customer_name'] . ' (' . $row['reference'] . ')',
            'deletedDate' => \date('d M Y', \strtotime((string) $row['deleted_at'])),
            'changed_by' => $row['changed_by'] ?? null,
            'changed_by_avatar' => $row['changed_by_avatar'] ?? null,
        ];
    }

    private function formatTable(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'seats' => (int) $row['seats'],
            'enabled' => (bool) $row['enabled'],
            'sortOrder' => (int) $row['sort_order'],
            'reservationCount' => (int) $row['reservation_count'],
            'enabledBadge' => $row['enabled'] ? '<span class="badge bg-label-success">On</span>' : '<span class="badge bg-label-secondary">Off</span>',
        ];
    }

    private function statusBadge(string $status): string
    {
        $colour = match ($status) {
            'requested' => 'warning',
            'confirmed' => 'success',
            default => 'secondary',
        };

        return '<span class="badge bg-label-' . $colour . '">' . \ucfirst($status) . '</span>';
    }

    private function logChange(string $action, int $id, string $name): void
    {
        $this->admin->model->changelog->log(
            description: $action . ': ' . $name,
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );
    }
}
