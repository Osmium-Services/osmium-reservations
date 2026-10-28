# Osmium Reservations

Table reservation requests for restaurants, cafes and pubs. A customer asks for a date, a time and a number of people; you confirm or decline, and they are emailed each step. Reservations are stored locally; other services (for example a calendar sync) can react to the `reservation.*` hooks.

It books **tables**, not seats: each table has a number of seats, and a booking is given the smallest free table that fits the party. It is not for one-to-one appointments (see Osmium Appointments) or for hiring equipment over several days (see Osmium Hire).

## Day to day (admin > Reservations)

A **Schedule** for one day with a column for each table, plus a "No table yet" column for requests still waiting: waiting requests and confirmed reservations as blocks at their time, with closed hours and the break shaded, and day arrows. Drag a block onto another table to move it: the tables it cannot go to (too few seats, or a confirmed booking in the way) are greyed while you drag, and a waiting request can be dragged onto a table, or back to "No table yet", without confirming it. Click a block to see who it is, then confirm or decline a request, change the time, party size or table, or cancel a confirmed one (each emails the customer). **New reservation** takes a phone booking, confirmed at once. When a party turns up press **Arrived** (in the edit box or the list): the block turns blue, nobody is emailed, and from then on the booking can't be dragged, moved or cancelled, and it keeps holding its table. Below the schedule, the list of reservations with the Deleted bin.

Confirming gives the reservation a table: the one you choose in the box, the one you already dragged it onto, or else the smallest free table with enough seats. Only confirmed and arrived reservations hold a table, so two waiting requests can ask for the same table; the second one to be confirmed is refused with the name of the booking in the way.

## Setup (admin > Services > Osmium Reservations)

- **Tables**: a name and a number of seats. A table can be switched off without deleting it. Customers never choose or see a table.
- **Booking rules**:
  - Opening hours come from Company > Location. Bookings may start from opening time up to closing time (or a narrower window set for each weekday, for example last bookings at 21:00), because guests may still be at their table after closing.
  - An optional break each weekday (for example between lunch and dinner): nothing can start in it.
  - **Dining time**: how long a table is held for each booking (for example 90 minutes). Customers are never told it and never asked to leave, but their calendar file ends the event after it.
  - **Turnaround**: minutes (0 to 60) a table is also held after each booking, to reset it. It counts both ways: nothing can start inside it, and a booking is refused if it would leave too little before the next one. It shows hatched on the Schedule and customers never see it.
  - Smallest party, and the largest party taken online (also never above the biggest table). A bigger party is told to phone, using the number in Company settings.
  - Minimum notice, how far ahead, time step, and who is emailed.
- When there is no table for the time asked for, the customer is told the nearest earlier and later times that day that have one.

## Theme

```php
$reservations = new \Osmium\Services\OsmiumReservations\Models\Reservations($this->osmium->dataSource);
$reservations->rules($this->osmium->config->company->openingHours ?? null); $reservations->pullNotice($_SESSION);
```

POST the form to `/api/reservations` with `token`, honeypot `zip`, `party_size`, `date` (Y-m-d), `time` (H:i), `name`, `email`, optional `phone` and `notes`.

## Updating

Updating from the Store replaces the files but does not run new migrations: after updating to 0.3.0, press Run Migrations (it adds the Arrived status).

## Hooks

`reservation.requested`, `reservation.confirmed`, `reservation.changed`, `reservation.declined`, `reservation.cancelled`. Payload: `reservation`, `reservationId`, `dataSource`, `osmium`. A service may store its own reference in the reservation's `external_refs`.

## Not included

Menus, food ordering, deposits or card holds, reminders, waiting lists, joining tables for larger parties, tables that differ by area (indoor, terrace), different dining times by party size, and a customer's own page to change or cancel (they reply to the email).
