<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumReservations\Models;

/**
 * What the host does with a reservation: take a request from the website, confirm, move, seat, decline or cancel one,
 * or book one for a caller. The admin screen and anything else that works the book (a test run, say) both go through
 * here, so there is one set of rules. Each method either does the job and returns its result or throws an
 * \InvalidArgumentException written for the person at the screen (or, for acceptRequest, for the customer).
 */
class ReservationDesk
{
    /**
     * @param \Closure|null $log called with (string $action, int $id, string $name) for each change, for the admin changelog
     */
    public function __construct(private object $osmium, private ?\Closure $log = null)
    {
    }

    private function record(string $action, int $id, string $name): void
    {
        if ($this->log !== null) ($this->log)($action, $id, $name);
    }

    /**
     * A request from the website (already parsed by ReservationRequest). When the exact table is free it is held for them and,
     * if the site answers automatically, booked at once; when it is full and the site answers automatically they are emailed the
     * nearest free times and the request waits for their answer.
     *
     * @return string the notice to show the customer
     * @throws \InvalidArgumentException a message fit for the customer
     */
    public function acceptRequest(array $request, \DateTimeImmutable $now): string
    {
        $config = ReservationConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null);
        $phone = \trim((string) ($this->osmium->config->company->phone ?? ''));
        ReservationRules::party(config: $config, partySize: $request['partySize'], largestTable: (new ReservationTable($this->osmium->dataSource))->largestParty(), phone: $phone);
        $slot = ReservationRules::slot(config: $config, date: $request['date'], time: $request['time'], now: $now);

        $reservations = new Reservation($this->osmium->dataSource);
        $alternatives = new ReservationAlternatives(reservations: $reservations, config: $config, now: $now);
        $auto = !empty($config['autoAnswer']);
        $notifier = new ReservationNotifier($this->osmium);

        $table = $reservations->findFreeTable(partySize: $request['partySize'], startsAt: $slot['startsAt'], endsAt: $slot['endsAt'], turnaroundMinutes: (int) $config['turnaroundMinutes']);
        $taken = $table === null;
        $times = $taken && $auto ? $alternatives->nearestTimes(date: $request['date'], requested: $request['time'], partySize: $request['partySize']) : [];
        $nothingToOffer = $taken && $times === [];
        if ($nothingToOffer) throw new \InvalidArgumentException($alternatives->message(date: $request['date'], time: $request['time'], partySize: $request['partySize']));

        $isDuplicate = $reservations->isDuplicate(email: $request['email'], startsAt: $slot['startsAt']);
        if ($isDuplicate) throw new \InvalidArgumentException('You have already asked for that time. We will be in touch.');

        $created = $reservations->create(request: $request, startsAt: $slot['startsAt'], endsAt: $slot['endsAt']);
        $id = (int) $created['id'];

        if ($taken) {
            $notifier->requested($reservations->get($id), false);
            $notifier->options($reservations->get($id), $times);

            return 'We are full at that time. We have emailed you the times that are free: please reply to take one.';
        }

        $reservations->setTable(id: $id, tableId: (int) $table['id']);
        if ($auto) $reservations->setStatus(id: $id, status: 'confirmed', sequence: 0);
        $notifier->requested($reservations->get($id), !$auto);
        if ($auto) $notifier->confirmed($reservations->get($id));

        return $auto ? 'Thanks, your table is booked. We have emailed you the details.' : 'Thanks, your request has been sent. We will confirm it by email.';
    }

    /** Confirming gives the reservation a table: the one chosen, the one it was already put on, or else the smallest free one that fits */
    public function confirm(int $id, int $tableId): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'confirmed');
        $alreadyPlaced = $tableId < 1 && $reservation['table_id'] !== null;
        if ($alreadyPlaced) $tableId = (int) $reservation['table_id']; // Put on a table by staff (dragged there): confirm keeps it
        $table = $this->tableFor(model: $model, reservation: $reservation, tableId: $tableId, partySize: (int) $reservation['party_size'], startsAt: $reservation['starts_at'], endsAt: $reservation['ends_at']);

        $model->setTable(id: $id, tableId: (int) $table['id']);
        $model->setStatus(id: $id, status: 'confirmed', sequence: (int) $reservation['ics_sequence']);
        $this->record(action: 'Confirmed', id: $id, name: $reservation['customer_name']);
        (new ReservationNotifier($this->osmium))->confirmed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    /** The party has arrived: the table is theirs, so the booking can no longer be moved or cancelled. Nobody is emailed. */
    public function seat(int $id): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $this->findOpen(model: $model, id: $id, allowed: ['confirmed'], verb: 'marked as arrived');

        $model->setStatus(id: $id, status: 'seated', sequence: (int) $reservation['ics_sequence']);
        $this->record(action: 'Arrived', id: $id, name: $reservation['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    public function decline(int $id, string $message): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'declined');

        $model->setStatus(id: $id, status: 'declined', sequence: (int) $reservation['ics_sequence']);
        $this->record(action: 'Declined', id: $id, name: $reservation['customer_name']);
        (new ReservationNotifier($this->osmium))->declined(reservation: $model->get($id), message: \mb_substr($message, 0, 1000));

        return ['success' => true, 'id' => $id];
    }

    public function cancel(int $id, string $message): array
    {
        $model = new Reservation($this->osmium->dataSource);
        $reservation = $this->findOpen(model: $model, id: $id, allowed: ['requested', 'confirmed'], verb: 'cancelled');

        $model->setStatus(id: $id, status: 'cancelled', sequence: (int) $reservation['ics_sequence'] + 1);
        $this->record(action: 'Cancelled', id: $id, name: $reservation['customer_name']);
        (new ReservationNotifier($this->osmium))->cancelled(reservation: $model->get($id), message: \mb_substr($message, 0, 1000));

        return ['success' => true, 'id' => $id];
    }

    /**
     * A reservation made for a customer who rang or called in: confirmed at once (and emailed, when there is an
     * address). Opening hours, breaks and clashes still apply; the notice and how-far-ahead limits are for the website.
     */
    public function createByStaff(array $input): array
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
        $this->record(action: 'Created by staff', id: $created['id'], name: $name);
        (new ReservationNotifier($this->osmium))->confirmed($model->get($created['id']));

        return ['success' => true, 'id' => $created['id']];
    }

    public function reschedule(int $id, array $input): array
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

        $movedWhileSeated = $reservation['status'] === 'seated' && $changed;
        if ($movedWhileSeated) throw new \InvalidArgumentException('This party has arrived, so the time, people and table cannot be changed');
        if ($changed) ReservationRules::staffSlot(config: $config, start: $start);

        $isConfirmed = $reservation['status'] === 'confirmed';
        $replace = $reservation['status'] === 'requested' && $timeChanged && $chosenId === $currentTableId;
        $needsTable = $isConfirmed || $chosenId > 0 || $replace;
        $table = $needsTable ? $this->tableFor(model: $model, reservation: $reservation, tableId: $chosenId, partySize: $partySize, startsAt: $startsAt, endsAt: $endsAt, keepIfFree: $replace) : null;

        $sequence = (int) $reservation['ics_sequence'] + ($isConfirmed && $timeChanged ? 1 : 0);
        $notes = \trim((string) ($input['admin_notes'] ?? ''));

        $model->reschedule(id: $id, partySize: $partySize, tableId: $table !== null ? (int) $table['id'] : null, startsAt: $startsAt, endsAt: $endsAt, adminNotes: $notes !== '' ? $notes : null, sequence: $sequence);
        $this->record(action: 'Updated', id: $id, name: $reservation['customer_name']);

        // The page asks whether to tell the customer; without an answer they are emailed, as before
        $emailThem = !\array_key_exists('notify_email', $input) || !empty($input['notify_email']);
        $textThem = !empty($input['notify_sms']);
        $tellCustomer = $isConfirmed && $timeChanged;
        if ($tellCustomer) (new ReservationNotifier($this->osmium))->changed(reservation: $model->get($id), email: $emailThem, sms: $textThem);

        return ['success' => true, 'id' => $id];
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
    /**
     * @param bool $keepIfFree a waiting request that has been moved: stay on its table if that is still free, else take another
     */
    private function tableFor(Reservation $model, ?array $reservation, int $tableId, int $partySize, string $startsAt, string $endsAt, bool $keepIfFree = false): array
    {
        $excludeId = $reservation !== null ? (int) $reservation['id'] : 0;
        $turnaround = ReservationConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null)['turnaroundMinutes'];

        if ($keepIfFree) {
            try {
                return $this->tableFor(model: $model, reservation: $reservation, tableId: $tableId, partySize: $partySize, startsAt: $startsAt, endsAt: $endsAt);
            } catch (NoTableException $e) {
                throw $e;
            } catch (\InvalidArgumentException $e) {
                $tableId = 0; // Its table no longer suits: look for another
            }
        }

        if ($tableId < 1) {
            $free = $model->findFreeTable(partySize: $partySize, startsAt: $startsAt, endsAt: $endsAt, excludeId: $excludeId, turnaroundMinutes: $turnaround);
            if ($free === null) throw $this->noTable(model: $model, startsAt: $startsAt, partySize: $partySize, excludeId: $excludeId);

            return $free;
        }

        $table = (new ReservationTable($this->osmium->dataSource))->get($tableId);
        $unusable = $table === null || !$table['enabled'];
        if ($unusable) throw new \InvalidArgumentException('Choose a table that is switched on');

        $tooSmall = (int) $table['seats'] < $partySize;
        if ($tooSmall) throw new \InvalidArgumentException($table['name'] . ' seats ' . (int) $table['seats'] . ', so it is too small for ' . $partySize . '. Choose another table.');

        $clash = $model->findOverlap(excludeId: $excludeId, tableId: $tableId, startsAt: $startsAt, endsAt: $endsAt, turnaroundMinutes: $turnaround);
        if ($clash !== null) {
            $when = (new \DateTimeImmutable($clash['starts_at']))->format('D j M, H:i');
            throw new \InvalidArgumentException($table['name'] . ' is already taken by the reservation for ' . $clash['customer_name'] . ' (' . $when . ')' . ($turnaround > 0 ? ', counting the ' . $turnaround . ' minute turnaround' : '') . '. Choose another table or change the time.');
        }

        return $table;
    }

    /** The refusal when nothing is free, with the nearest times that day that do have a table */
    private function noTable(Reservation $model, string $startsAt, int $partySize, int $excludeId): NoTableException
    {
        $start = new \DateTimeImmutable($startsAt);
        $config = ReservationConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null);
        $alternatives = new ReservationAlternatives(reservations: $model, config: $config, now: $start->setTime(0, 0));
        $times = $alternatives->nearestTimes(date: $start->format('Y-m-d'), requested: $start->format('H:i'), partySize: $partySize, excludeId: $excludeId);

        $message = 'No table for ' . $partySize . ' is free at ' . $start->format('H:i \o\n D j M') . '.';
        $message .= $times === [] ? ' No other time that day has one either.' : ' The nearest free times are ' . \implode(' and ', $times) . '.';

        return new NoTableException($message, $times);
    }

}
