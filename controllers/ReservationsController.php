<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\OsmiumReservations\Models\NoTableException;
use Osmium\Services\OsmiumReservations\Models\Reservation;
use Osmium\Services\OsmiumReservations\Models\ReservationDesk;
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
        $this->data['admin']['smsReady'] = (new ReservationNotifier($this->osmium))->smsReady();
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

        $needsId = \in_array($action, ['confirm', 'seat', 'decline', 'cancel', 'reschedule', 'delete', 'restore', 'permanently_delete', 'delete_table'], true);
        $missingParams = !$action || ($needsId && !$id);
        if ($missingParams) $this->admin->jsonError('Missing parameters');

        try {
            $result = match ($action) {
                'confirm' => $this->desk()->confirm(id: $id, tableId: (int) ($input['table_id'] ?? 0)),
                'snapshot' => $this->snapshot(),
                'seat' => $this->desk()->seat($id),
                'decline' => $this->desk()->decline(id: $id, message: \trim((string) ($input['message'] ?? ''))),
                'cancel' => $this->desk()->cancel(id: $id, message: \trim((string) ($input['message'] ?? ''))),
                'create' => $this->desk()->createByStaff($input),
                'reschedule' => $this->desk()->reschedule(id: $id, input: $input),
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
        } catch (NoTableException $e) {
            $this->admin->jsonError($e->getMessage(), 200, ['alternatives' => $e->times]);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Reservations
    // =========================================================================

    /** Everything the schedule draws, as it is right now: the Live view asks for this every few seconds */
    private function snapshot(): array
    {
        $db = $this->osmium->dataSource;

        return [
            'success' => true,
            'reservations' => \array_column(\array_map($this->formatReservation(...), (new Reservation($db))->listAll()), null, 'id'),
            'tables' => \array_column(\array_map($this->formatTable(...), (new ReservationTable($db))->listAll()), null, 'id'),
        ];
    }

    /** The host's desk, where all the rules live; each change it makes is written to the admin changelog */
    private function desk(): ReservationDesk
    {
        return new ReservationDesk(osmium: $this->osmium, log: fn(string $action, int $id, string $name) => $this->logChange(action: $action, id: $id, name: $name));
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
            'turnaroundMinutes' => $input['turnaround_minutes'] ?? 0,
            'minParty' => $input['min_party'] ?? 0,
            'maxParty' => $input['max_party'] ?? 0,
            'minNoticeHours' => $input['min_notice_hours'] ?? -1,
            'maxDaysAhead' => $input['max_days_ahead'] ?? 0,
            'autoAnswer' => !empty($input['auto_answer']),
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
            'endTime' => $this->endTime(start: $start, endsAt: $row['ends_at']),
            'date' => $start->format('Y-m-d'),
            'time' => $start->format('H:i'),
            'customerNotes' => $row['customer_notes'] ?? '',
            'adminNotes' => $row['admin_notes'] ?? '',
        ];
    }

    /** The time of day the table is free again; a meal running past midnight is drawn to the end of its day (24:00) */
    private function endTime(\DateTimeImmutable $start, string $endsAt): string
    {
        $end = new \DateTimeImmutable($endsAt);

        return $end->format('Y-m-d') === $start->format('Y-m-d') ? $end->format('H:i') : '24:00';
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
            'seated' => 'info',
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
