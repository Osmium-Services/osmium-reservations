<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * The reservations: a customer's request and what the business did with it. Soft-deleted first, removed for good from the bin.
 */
class Reservation extends Model
{
    public const STATUSES = ['requested', 'confirmed', 'seated', 'declined', 'cancelled'];

    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'reservations');
    }

    /** Every reservation not in the bin, requests first, then newest time first, with its table */
    public function listAll(): array
    {
        return $this->rows('reservation-list.sql');
    }

    public function listDeleted(): array
    {
        return $this->rows('reservation-list-deleted.sql');
    }

    public function countRequested(): int
    {
        $this->database->query($this->loadSqlFile('reservation-count-requested.sql'));
        $row = $this->database->single();

        return (int) ($row['total'] ?? 0);
    }

    public function get(int $id): ?array
    {
        return $this->one(file: 'reservation-get.sql', param: ':id', value: $id);
    }

    public function getByReference(string $reference): ?array
    {
        return $this->one(file: 'reservation-get-by-reference.sql', param: ':reference', value: $reference);
    }

    /**
     * @param array{partySize: int, tableId: ?int, name: string, email: string, phone: ?string, notes: ?string} $request
     * @return array{id: int, reference: string}
     */
    public function create(array $request, string $startsAt, string $endsAt): array
    {
        $reference = $this->newReference();

        $this->database->query($this->loadSqlFile('reservation-insert.sql'));
        $this->database->bind(param: ':reference', value: $reference);
        $this->database->bind(param: ':table_id', value: $request['tableId'] ?? null);
        $this->database->bind(param: ':party_size', value: $request['partySize']);
        $this->database->bind(param: ':customer_name', value: $request['name']);
        $this->database->bind(param: ':customer_email', value: $request['email']);
        $this->database->bind(param: ':customer_phone', value: $request['phone']);
        $this->database->bind(param: ':customer_notes', value: $request['notes']);
        $this->database->bind(param: ':starts_at', value: $startsAt);
        $this->database->bind(param: ':ends_at', value: $endsAt);
        $this->database->execute();

        return ['id' => (int) $this->database->lastInsertId(), 'reference' => $reference];
    }

    public function reschedule(int $id, int $partySize, ?int $tableId, string $startsAt, string $endsAt, ?string $adminNotes, int $sequence): void
    {
        $this->database->query($this->loadSqlFile('reservation-reschedule.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':party_size', value: $partySize);
        $this->database->bind(param: ':table_id', value: $tableId);
        $this->database->bind(param: ':starts_at', value: $startsAt);
        $this->database->bind(param: ':ends_at', value: $endsAt);
        $this->database->bind(param: ':admin_notes', value: $adminNotes);
        $this->database->bind(param: ':ics_sequence', value: $sequence);
        $this->database->execute();
    }

    public function setStatus(int $id, string $status, int $sequence): void
    {
        $this->database->query($this->loadSqlFile('reservation-set-status.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':status', value: $status);
        $this->database->bind(param: ':ics_sequence', value: $sequence);
        $this->database->execute();
    }

    /**
     * What other services have stored against the reservation, such as a calendar event id: service id => value.
     * Each service keeps to its own key, so one can never overwrite another's.
     *
     * @return array<string, mixed>
     */
    public static function externalRefs(array $reservation): array
    {
        $decoded = \json_decode((string) ($reservation['external_refs'] ?? ''), associative: true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function setExternalRef(int $id, string $serviceId, mixed $value): void
    {
        $reservation = $this->get($id);
        if ($reservation === null) return;

        $refs = self::externalRefs($reservation);
        $refs[$serviceId] = $value;

        $this->database->query($this->loadSqlFile('reservation-set-refs.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':external_refs', value: \json_encode($refs));
        $this->database->execute();
    }

    /**
     * The smallest enabled table with room for the party that has no confirmed or seated reservation overlapping the time, or
     * null when there is none. The reservation being moved (excludeId, 0 for a new one) never counts against itself.
     *
     * @return array<string, mixed>|null
     */
    public function findFreeTable(int $partySize, string $startsAt, string $endsAt, int $excludeId = 0, int $turnaroundMinutes = 0): ?array
    {
        $held = $this->held(startsAt: $startsAt, endsAt: $endsAt, turnaroundMinutes: $turnaroundMinutes);
        $this->database->query($this->loadSqlFile('reservation-free-table.sql'));
        $this->database->bind(param: ':party_size', value: $partySize);
        $this->database->bind(param: ':id', value: $excludeId);
        $this->database->bind(param: ':starts_at', value: $held['startsAt']);
        $this->database->bind(param: ':ends_at', value: $held['endsAt']);
        $result = $this->database->single();

        return $result ?: null;
    }

    /** The earliest confirmed or seated reservation on this table that overlaps the time, other than the reservation itself */
    public function findOverlap(int $excludeId, int $tableId, string $startsAt, string $endsAt, int $turnaroundMinutes = 0): ?array
    {
        $held = $this->held(startsAt: $startsAt, endsAt: $endsAt, turnaroundMinutes: $turnaroundMinutes);
        $this->database->query($this->loadSqlFile('reservation-overlapping.sql'));
        $this->database->bind(param: ':id', value: $excludeId);
        $this->database->bind(param: ':table_id', value: $tableId);
        $this->database->bind(param: ':starts_at', value: $held['startsAt']);
        $this->database->bind(param: ':ends_at', value: $held['endsAt']);
        $result = $this->database->single();

        return $result ?: null;
    }

    /**
     * The stretch a booking needs a table for: its own time with the turnaround added either side, so it neither starts
     * inside the previous booking's turnaround nor leaves too little turnaround before the next one. Never shown to customers.
     *
     * @return array{startsAt: string, endsAt: string}
     */
    private function held(string $startsAt, string $endsAt, int $turnaroundMinutes): array
    {
        $none = $turnaroundMinutes < 1;
        if ($none) return ['startsAt' => $startsAt, 'endsAt' => $endsAt];

        return [
            'startsAt' => (new \DateTimeImmutable($startsAt))->modify("-{$turnaroundMinutes} minutes")->format('Y-m-d H:i:s'),
            'endsAt' => (new \DateTimeImmutable($endsAt))->modify("+{$turnaroundMinutes} minutes")->format('Y-m-d H:i:s'),
        ];
    }

    /** The same person asking for the same time twice */
    public function isDuplicate(string $email, string $startsAt): bool
    {
        $this->database->query($this->loadSqlFile('reservation-duplicate.sql'));
        $this->database->bind(param: ':email', value: $email);
        $this->database->bind(param: ':starts_at', value: $startsAt);

        return (bool) $this->database->single();
    }

    /** Gives a table to a reservation without touching anything else (used when it is confirmed) */
    public function setTable(int $id, int $tableId): void
    {
        $this->database->query($this->loadSqlFile('reservation-set-table.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':table_id', value: $tableId);
        $this->database->execute();
    }

    public function softDelete(int $id): void
    {
        $this->run(file: 'reservation-soft-delete.sql', id: $id);
    }

    public function restore(int $id): void
    {
        $this->run(file: 'reservation-restore.sql', id: $id);
    }

    public function hardDelete(int $id): void
    {
        $this->run(file: 'reservation-hard-delete.sql', id: $id);
    }

    /** @return int How many reservations were removed */
    public function purgeDeleted(): int
    {
        $this->database->query($this->loadSqlFile('reservation-purge-deleted.sql'));
        $this->database->execute();

        return $this->database->rowCount();
    }

    /** Ten characters from letters and digits that are hard to misread, unique among reservations */
    private function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $reference = '';
            for ($i = 0; $i < 10; $i++) {
                $reference .= $alphabet[\random_int(0, \strlen($alphabet) - 1)];
            }
            $this->database->query($this->loadSqlFile('reservation-reference-taken.sql'));
            $this->database->bind(param: ':reference', value: $reference);
            $taken = (bool) $this->database->single();
        } while ($taken);

        return $reference;
    }

    private function rows(string $file): array
    {
        $this->database->query($this->loadSqlFile($file));

        return $this->database->resultset();
    }

    private function one(string $file, string $param, int|string $value): ?array
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: $param, value: $value);
        $result = $this->database->single();

        return $result ?: null;
    }

    private function run(string $file, int $id): void
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }
}
