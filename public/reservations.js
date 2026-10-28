(function () {
    'use strict';

    const config = window.OsmiumReservationsConfig || {};
    const actionUrl = config.basePath + 'reservations/action/';

    function byId(id) {
        return document.getElementById(id);
    }

    function show(resultId, kind, message) {
        const box = byId(resultId);
        box.className = 'alert alert-' + kind + (resultId === 'settingsResult' ? ' mb-0 py-2' : '');
        box.textContent = message;
    }

    function clear(resultId) {
        const box = byId(resultId);
        box.className = 'alert d-none';
        box.textContent = '';
    }

    async function post(payload) {
        const response = await fetch(actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: window.csrfToken }, payload))
        });

        return response.json();
    }

    // Saves a form's payload, reloads on success and shows the problem in the form's own message box otherwise
    async function submit(event, resultId, payload) {
        event.preventDefault();
        clear(resultId);

        try {
            const result = await post(payload);
            if (result.success) {
                window.location.reload();
                return;
            }

            show(resultId, 'danger', result.error || 'Failed to save');
        } catch (error) {
            show(resultId, 'danger', 'An error occurred while saving');
        }
    }

    // Attaches a handler when the element is on this page (the schedule page and the setup page share this script)
    function on(id, event, handler) {
        const node = byId(id);
        if (node) node.addEventListener(event, handler);
    }

    function openModal(id) {
        new bootstrap.Modal(byId(id)).show();
    }

    const DECISIONS = {
        confirm: { button: 'reservationConfirmButton', label: 'Confirm', statuses: ['requested'], sure: false },
        decline: { button: 'reservationDeclineButton', label: 'Decline request', statuses: ['requested'], sure: true },
        cancel: { button: 'reservationCancelButton', label: 'Cancel reservation', statuses: ['confirmed'], sure: true }
    };
    let pendingDecision = null;
    let pendingTimer = null;
    let currentStatus = '';

    function resetDecisionButtons(status) {
        pendingDecision = null;
        window.clearTimeout(pendingTimer);
        byId('reservationDeclineBox').classList.add('d-none');
        Object.keys(DECISIONS).forEach(function (name) {
            const decision = DECISIONS[name];
            const button = byId(decision.button);
            button.textContent = decision.label;
            button.classList.toggle('d-none', decision.statuses.indexOf(status) === -1);
            button.disabled = false;
        });

        // Waiting for an answer, Confirm is the main action; once confirmed, saving changes is
        const save = byId('reservationSaveButton');
        save.classList.toggle('btn-primary', status !== 'requested');
        save.classList.toggle('btn-outline-primary', status === 'requested');
    }

    // Decline and Cancel email the customer, so the first click asks "sure?" and the second one does it
    async function decide(name) {
        const decision = DECISIONS[name];
        const button = byId(decision.button);

        if (decision.sure && pendingDecision !== name) {
            resetDecisionButtons(currentStatus);
            pendingDecision = name;
            button.textContent = 'Click again to ' + decision.label.toLowerCase();
            byId('reservationDeclineBox').classList.toggle('d-none', name === 'confirm');
            // Declining or cancelling may need a few words for the customer, so give time to type them
            const waitMs = 120000;
            pendingTimer = window.setTimeout(function () { resetDecisionButtons(currentStatus); }, waitMs);
            return;
        }

        clear('reservationResult');
        button.disabled = true;
        try {
            const payload = { action: name, id: byId('reservationId').value };
            if (name === 'confirm') payload.table_id = byId('reservationTable').value;
            if (name !== 'confirm') payload.message = byId('reservationDeclineMessage').value;
            const result = await post(payload);
            if (result.success) {
                window.location.reload();
                return;
            }

            show('reservationResult', 'danger', result.error || 'That did not work');
        } catch (error) {
            show('reservationResult', 'danger', 'An error occurred');
        }
        resetDecisionButtons(currentStatus);
    }

    // A booking taken by phone or in person: the form opens empty, on the day being looked at
    function openNewReservation(dateText) {
        clear('reservationResult');
        byId('reservationForm').reset();
        byId('reservationId').value = '';
        currentStatus = '';
        resetDecisionButtons('');
        byId('reservationModalTitle').textContent = 'New reservation';
        byId('reservationNewFields').classList.remove('d-none');
        byId('reservationCustomer').classList.add('d-none');
        byId('reservationWho').classList.add('d-none');
        byId('reservationTheirNotes').textContent = '';
        byId('reservationNotesLabel').textContent = 'Notes (kept on the booking)';
        byId('reservationSaveButton').textContent = 'Book it';
        byId('reservationSaveButton').classList.add('btn-primary');
        byId('reservationSaveButton').classList.remove('btn-outline-primary');
        byId('reservationTable').value = '0';
        byId('reservationParty').value = 2;
        byId('reservationDate').value = dateText;
        openModal('reservationModal');
    }

    function openReservation(reservation) {
        clear('reservationResult');
        byId('reservationModalTitle').textContent = 'Edit reservation';
        byId('reservationNewFields').classList.add('d-none');
        byId('reservationCustomer').classList.remove('d-none');
        byId('reservationWho').classList.remove('d-none');
        byId('reservationNotesLabel').textContent = 'Your notes (never shown to the customer)';
        byId('reservationSaveButton').textContent = 'Save changes';
        currentStatus = reservation.status;
        resetDecisionButtons(reservation.status);
        byId('reservationId').value = reservation.id;
        byId('reservationCustomer').textContent = [reservation.customerName, reservation.customerEmail, reservation.customerPhone].filter(Boolean).join(' · ');
        byId('reservationWho').textContent = reservation.reference + ' · ' + (reservation.status === 'requested' ? 'waiting for you' : reservation.status);
        byId('reservationTheirNotes').textContent = reservation.customerNotes ? 'They wrote: ' + reservation.customerNotes : '';
        byId('reservationParty').value = reservation.partySize;
        byId('reservationTable').value = reservation.tableId === '' ? '0' : reservation.tableId;
        byId('reservationDate').value = reservation.date;
        byId('reservationTime').value = reservation.time;
        byId('reservationNotes').value = reservation.adminNotes;
        openModal('reservationModal');
    }

    function openTable(table) {
        clear('tableResult');
        const isEdit = Boolean(table);
        byId('tableModalTitle').textContent = isEdit ? 'Edit table' : 'Add a table';
        byId('tableId').value = isEdit ? table.id : '';
        byId('tableName').value = isEdit ? table.name : '';
        byId('tableSeats').value = isEdit ? table.seats : 2;
        byId('tableOrder').value = isEdit ? table.sortOrder : 0;
        byId('tableEnabled').checked = isEdit ? table.enabled : true;
        openModal('tableModal');
    }

    function settingsPayload() {
        const payload = {
            action: 'save_settings',
            step_minutes: byId('settingsStep').value,
            dining_minutes: byId('settingsDining').value,
            turnaround_minutes: byId('settingsTurnaround').value,
            min_party: byId('settingsMinParty').value,
            max_party: byId('settingsMaxParty').value,
            min_notice_hours: byId('settingsNotice').value,
            max_days_ahead: byId('settingsAhead').value,
            notify_emails: byId('settingsEmails').value
        };

        for (let day = 1; day <= 7; day++) {
            payload['window_start_' + day] = byId('wfrom_' + day).value;
            payload['window_end_' + day] = byId('wto_' + day).value;
            payload['break_start_' + day] = byId('bfrom_' + day).value;
            payload['break_end_' + day] = byId('bto_' + day).value;
        }

        return payload;
    }

    // ---- Schedule: one day, a column for each table, and one for bookings that have no table yet ----

    const PX_PER_MINUTE = 1.3;

    function toMinutes(time) {
        const parts = time.split(':');

        return Number(parts[0]) * 60 + Number(parts[1]);
    }

    function pad(number) {
        return String(number).padStart(2, '0');
    }

    function clock(minutes) {
        return pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60);
    }

    function isoDate(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function el(tag, className, styles) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (styles) Object.assign(node.style, styles);

        return node;
    }

    function shade(parent, className, fromMinutes, toMinutes_, origin) {
        if (toMinutes_ <= fromMinutes) return;

        parent.appendChild(el('div', 'sched-shade ' + className, {
            top: (fromMinutes - origin) * PX_PER_MINUTE + 'px',
            height: (toMinutes_ - fromMinutes) * PX_PER_MINUTE + 'px'
        }));
    }

    function weekdayOf(date) {
        return (date.getDay() + 6) % 7 + 1;
    }

    function activeOn(dateText) {
        return Object.values(config.reservations).filter(function (item) {
            return item.date === dateText && (item.status === 'requested' || item.status === 'confirmed');
        });
    }

    function enabledTables() {
        return Object.values(config.tables).filter(function (table) { return table.enabled; });
    }

    // A column for each table, plus one for requests still waiting for a table (or whose table has since been switched off)
    function scheduleColumns(dateText) {
        const tables = enabledTables();
        const known = tables.map(function (table) { return String(table.id); });
        const columns = tables.map(function (table) {
            return { title: table.name + ' (' + table.seats + ')', table: String(table.id) };
        });

        const loose = activeOn(dateText).some(function (item) { return known.indexOf(String(item.tableId)) === -1; });
        if (loose || columns.length === 0) columns.push({ title: columns.length === 0 ? 'Reservations' : 'No table yet', table: null });

        return columns;
    }

    function itemsFor(column, dateText) {
        const known = enabledTables().map(function (table) { return String(table.id); });

        return activeOn(dateText).filter(function (item) {
            return column.table === null ? known.indexOf(String(item.tableId)) === -1 : String(item.tableId) === column.table;
        });
    }

    // ---- Dragging a booking to another table ----

    const DRAG_THRESHOLD = 6;
    let justDragged = false;

    // Why this booking cannot sit at that table, or '' when it can: switched off, too few seats, or a confirmed booking in the way
    function whyNot(item, tableId) {
        const table = config.tables[tableId];
        if (!table || !table.enabled) return 'That table is switched off';
        if (Number(table.seats) < Number(item.partySize)) return table.name + ' seats ' + table.seats + ', too small for ' + item.partySize;

        const turnaround = config.schedule.turnaround || 0;
        const start = toMinutes(item.time);
        const end = toMinutes(item.endTime);
        const clash = Object.values(config.reservations).filter(function (other) {
            return other.id !== item.id && other.status === 'confirmed' && other.date === item.date && String(other.tableId) === String(tableId)
                && toMinutes(other.time) < end + turnaround && toMinutes(other.endTime) + turnaround > start;
        })[0];

        return clash ? table.name + ' is taken by ' + clash.customerName + ' at ' + clash.time : '';
    }

    // Lights up the tables the booking could go to and greys the rest; a waiting request may also go back to "No table yet"
    function markLanes(lanes, item) {
        lanes.forEach(function (lane) {
            const isCurrent = lane.dataset.table === String(item.tableId);
            const noTable = lane.dataset.table === '';
            const reason = noTable ? (item.status === 'requested' ? '' : 'A confirmed booking needs a table') : whyNot(item, lane.dataset.table);
            lane.dataset.reason = reason;
            lane.classList.toggle('sched-yes', reason === '' && !isCurrent);
            lane.classList.toggle('sched-no', reason !== '' && !isCurrent);
        });
    }

    function clearLanes(lanes) {
        lanes.forEach(function (lane) { lane.classList.remove('sched-yes', 'sched-no', 'sched-over'); });
    }

    function laneAt(lanes, x) {
        return lanes.filter(function (lane) {
            const box = lane.getBoundingClientRect();

            return x >= box.left && x < box.right;
        })[0] || null;
    }

    // blockedReason is why the lane was greyed while dragging ('' when it was fine); the lane's own shading is gone by now
    async function dropOnTable(item, lane, blockedReason) {
        clear('scheduleMessage');
        const sameTable = lane === null || lane.dataset.table === String(item.tableId);
        if (sameTable) return;
        if (blockedReason !== '') {
            show('scheduleMessage', 'warning', blockedReason);
            return;
        }

        try {
            const result = await post({
                action: 'reschedule', id: item.id, party_size: item.partySize, table_id: lane.dataset.table || 0,
                date: item.date, time: item.time, admin_notes: item.adminNotes
            });
            if (result.success) {
                window.location.reload();
                return;
            }

            show('scheduleMessage', 'warning', result.error || 'That did not work');
        } catch (error) {
            show('scheduleMessage', 'danger', 'An error occurred');
        }
    }

    // A press that moves further than a few pixels is a drag; one that does not is a click and opens the booking
    function makeDraggable(block, item) {
        block.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) return;

            const startX = e.clientX;
            const startY = e.clientY;
            const lanes = Array.from(document.querySelectorAll('.sched-col'));
            let dragging = false;
            let target = null;

            const move = function (ev) {
                const dx = ev.clientX - startX;
                const dy = ev.clientY - startY;
                if (!dragging && Math.hypot(dx, dy) < DRAG_THRESHOLD) return;

                if (!dragging) {
                    dragging = true;
                    block.classList.add('sched-dragging');
                    markLanes(lanes, item);
                }
                block.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';
                target = laneAt(lanes, ev.clientX);
                lanes.forEach(function (lane) { lane.classList.toggle('sched-over', lane === target && lane.classList.contains('sched-yes')); });
            };
            const finish = function () {
                block.removeEventListener('pointermove', move);
                block.removeEventListener('pointerup', finish);
                block.removeEventListener('pointercancel', finish);
                block.style.transform = '';
                block.classList.remove('sched-dragging');
                if (!dragging) return;

                justDragged = true;
                window.setTimeout(function () { justDragged = false; }, 100);
                const lane = target;
                const blockedReason = lane !== null && lane.classList.contains('sched-no') ? lane.dataset.reason : '';
                clearLanes(lanes);
                dropOnTable(item, lane, blockedReason);
            };

            block.setPointerCapture(e.pointerId);
            block.addEventListener('pointermove', move);
            block.addEventListener('pointerup', finish);
            block.addEventListener('pointercancel', finish);
        });
    }

    function renderSchedule(dateText) {
        const grid = byId('scheduleGrid');
        clear('scheduleMessage');
        const date = new Date(dateText + 'T00:00:00');
        byId('scheduleTitle').textContent = date.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).replace(',', '');
        grid.textContent = '';

        const hours = config.schedule.hours[weekdayOf(date)] || null;
        const columns = scheduleColumns(dateText);
        columns.forEach(function (column) { column.items = itemsFor(column, dateText); });

        const nothingToShow = hours === null && columns.every(function (column) { return column.items.length === 0; });
        if (nothingToShow) {
            grid.appendChild(el('div', 'sched-note')).textContent = 'Closed this day.';
            return;
        }

        // The visible hours: opening to closing, widened to take in anything already booked outside it (a meal runs past closing)
        let first = hours ? toMinutes(hours.open) : 24 * 60;
        let last = hours ? toMinutes(hours.close) : 0;
        columns.forEach(function (column) {
            column.items.forEach(function (item) {
                first = Math.min(first, toMinutes(item.time));
                last = Math.max(last, toMinutes(item.endTime));
            });
        });
        const origin = Math.floor(first / 60) * 60;
        const end = Math.ceil(last / 60) * 60;
        const height = (end - origin) * PX_PER_MINUTE;

        const table = el('div', 'sched', { gridTemplateColumns: '3.5rem repeat(' + columns.length + ', minmax(5.5rem, 1fr))' });
        table.appendChild(el('div', 'sched-head'));
        columns.forEach(function (column) { table.appendChild(el('div', 'sched-head')).textContent = column.title; });

        const times = el('div', 'sched-times', { height: height + 'px' });
        for (let at = origin; at <= end; at += 60) {
            const label = el('div', 'sched-time', { top: (at - origin) * PX_PER_MINUTE + 'px' });
            label.textContent = clock(at);
            times.appendChild(label);
        }
        table.appendChild(times);

        columns.forEach(function (column) {
            const lane = el('div', 'sched-col', { height: height + 'px' });
            lane.dataset.table = column.table === null ? '' : column.table;
            for (let at = origin; at < end; at += 60) {
                lane.appendChild(el('div', 'sched-line', { top: (at - origin) * PX_PER_MINUTE + 'px' }));
            }

            // Bookings may start from opening up to the last booking time, so only before opening and in a break is shaded
            if (hours === null) {
                shade(lane, 'sched-closed', origin, end, origin);
            } else {
                shade(lane, 'sched-closed', origin, toMinutes(hours.open), origin);
                if (hours.breakStart) shade(lane, 'sched-break', toMinutes(hours.breakStart), toMinutes(hours.breakEnd), origin);
            }

            column.items.forEach(function (item) {
                const block = el('button', 'sched-appt sched-' + item.status, {
                    top: (toMinutes(item.time) - origin) * PX_PER_MINUTE + 'px',
                    height: Math.max(18, (toMinutes(item.endTime) - toMinutes(item.time)) * PX_PER_MINUTE - 2) + 'px'
                });
                block.type = 'button';
                block.title = item.time + ' to ' + item.endTime + ' ' + item.customerName + ', ' + item.partySize + ' (' + item.status + ')';
                block.appendChild(document.createTextNode(item.time + ' ' + item.customerName));
                block.appendChild(el('small')).textContent = 'Party of ' + item.partySize;
                block.addEventListener('click', function () { if (!justDragged) openReservation(item); });
                makeDraggable(block, item);
                lane.appendChild(block);
                if (config.schedule.turnaround > 0) {
                    lane.appendChild(el('div', 'sched-shade sched-turn', {
                        top: (toMinutes(item.endTime) - origin) * PX_PER_MINUTE + 'px',
                        height: config.schedule.turnaround * PX_PER_MINUTE + 'px'
                    })).title = 'Turnaround: held to reset the table';
                }
            });

            table.appendChild(lane);
        });

        grid.appendChild(table);
    }

    function rememberDate() {
        try {
            window.localStorage.setItem('osmiumReservationsDate', byId('scheduleDate').value);
        } catch (error) {
            // Private windows can refuse storage; the date just is not remembered
        }
    }

    function rememberedDate() {
        try {
            const saved = window.localStorage.getItem('osmiumReservationsDate') || '';

            return /^\d{4}-\d\d-\d\d$/.test(saved) ? new Date(saved + 'T00:00:00') : new Date();
        } catch (error) {
            return new Date();
        }
    }

    function initSchedule() {
        const input = byId('scheduleDate');
        const draw = function () {
            rememberDate();
            if (input.value) renderSchedule(input.value);
        };
        const show = function (date) {
            input.value = isoDate(date);
            draw();
        };
        const shift = function (direction) {
            const date = new Date(input.value + 'T00:00:00');
            date.setDate(date.getDate() + direction);
            show(date);
        };

        on('scheduleToday', 'click', function () { show(new Date()); });
        on('scheduleNew', 'click', function () { openNewReservation(input.value || isoDate(new Date())); });
        on('schedulePrev', 'click', function () { shift(-1); });
        on('scheduleNext', 'click', function () { shift(1); });
        input.addEventListener('change', function () { if (input.value) draw(); });
        show(rememberedDate());
    }

    function init() {
        if (byId('schedule')) initSchedule();

        // Row actions come from the ListViews; each Edit fires list:action with its own event name and the row's id
        document.addEventListener('list:action', function (e) {
            if (e.detail.action === 'edit') openReservation(config.reservations[e.detail.id]);
            if (e.detail.action === 'add-table') openTable(null);
            if (e.detail.action === 'edit-table') openTable(config.tables[e.detail.id]);
        });

        Object.keys(DECISIONS).forEach(function (name) {
            on(DECISIONS[name].button, 'click', function () { decide(name); });
        });

        on('reservationForm', 'submit', function (e) {
            const creating = byId('reservationId').value === '';
            submit(e, 'reservationResult', creating ? {
                action: 'create',
                name: byId('reservationName').value,
                phone: byId('reservationPhone').value,
                email: byId('reservationEmail').value,
                party_size: byId('reservationParty').value,
                table_id: byId('reservationTable').value,
                date: byId('reservationDate').value,
                time: byId('reservationTime').value,
                notes: byId('reservationNotes').value
            } : {
                action: 'reschedule',
                id: byId('reservationId').value,
                party_size: byId('reservationParty').value,
                table_id: byId('reservationTable').value,
                date: byId('reservationDate').value,
                time: byId('reservationTime').value,
                admin_notes: byId('reservationNotes').value
            });
        });

        on('tableForm', 'submit', function (e) {
            submit(e, 'tableResult', {
                action: 'save_table',
                id: byId('tableId').value,
                name: byId('tableName').value,
                seats: byId('tableSeats').value,
                sort_order: byId('tableOrder').value,
                enabled: byId('tableEnabled').checked
            });
        });

        on('settingsForm', 'submit', function (e) {
            submit(e, 'settingsResult', settingsPayload());
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
