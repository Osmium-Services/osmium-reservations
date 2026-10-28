<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * A table the restaurant can seat people at: a name and how many seats. Customers never choose one; a booking is given
 * the smallest free table that fits the party.
 */
class ReservationTable extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'reservation_tables');
    }

    public function listAll(): array
    {
        $this->database->query($this->loadSqlFile('reservation-table-list.sql'));

        return $this->database->resultset();
    }

    /** @return list<array<string, mixed>> the tables customers can be seated at, in the order to try them */
    public function listEnabled(): array
    {
        return \array_values(\array_filter($this->listAll(), static fn(array $row): bool => (bool) $row['enabled']));
    }

    public function get(int $id): ?array
    {
        $this->database->query($this->loadSqlFile('reservation-table-get.sql'));
        $this->database->bind(param: ':id', value: $id);
        $result = $this->database->single();

        return $result ?: null;
    }

    public function create(string $name, int $seats, bool $enabled, int $sortOrder): int
    {
        $this->database->query($this->loadSqlFile('reservation-table-insert.sql'));
        $this->bindDetails(name: $name, seats: $seats, enabled: $enabled, sortOrder: $sortOrder);
        $this->database->execute();

        return (int) $this->database->lastInsertId();
    }

    public function update(int $id, string $name, int $seats, bool $enabled, int $sortOrder): void
    {
        $this->database->query($this->loadSqlFile('reservation-table-update.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->bindDetails(name: $name, seats: $seats, enabled: $enabled, sortOrder: $sortOrder);
        $this->database->execute();
    }

    public function delete(int $id): void
    {
        $this->database->query($this->loadSqlFile('reservation-table-delete.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }

    /** The seats of the biggest table that is switched on: the largest party the restaurant can take */
    public function largestParty(): int
    {
        $seats = \array_map(static fn(array $row): int => (int) $row['seats'], $this->listEnabled());

        return $seats === [] ? 0 : \max($seats);
    }

    private function bindDetails(string $name, int $seats, bool $enabled, int $sortOrder): void
    {
        $this->database->bind(param: ':name', value: $name);
        $this->database->bind(param: ':seats', value: $seats);
        $this->database->bind(param: ':enabled', value: $enabled ? 1 : 0);
        $this->database->bind(param: ':sort_order', value: $sortOrder);
    }
}
