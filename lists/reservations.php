<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Reservations lists, rendered by Osmium\Modules\Admin\Core\ListView. Row fields are prepared by
 * ReservationsController::formatReservation(), formatTable() and formatDeleted().
 */
require_once 'app/core/admin/services/ChangedByCell.php';

$changedByColumn = ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))];

return [
    'active' => [
        'id' => 'osmium-reservations',
        'title' => 'Reservations',
        'noun' => ['reservation', 'reservations'],
        'endpoint' => 'reservations/action/',
        'note' => 'Requests wait at the top until you confirm or decline them. The customer is emailed each time.',
        'emptyText' => 'No reservations yet.',
        'order' => null, // Server order: requests first, then latest time first
        'columns' => [
            ['key' => 'statusBadge', 'label' => 'Status', 'type' => 'raw', 'nowrap' => true],
            ['key' => 'whenText', 'label' => 'When', 'type' => 'text', 'nowrap' => true],
            ['key' => 'customerHtml', 'label' => 'Customer', 'type' => 'raw', 'fill' => true],
            ['key' => 'partySize', 'label' => 'People', 'type' => 'text', 'nowrap' => true],
            ['key' => 'tableName', 'label' => 'Table', 'type' => 'text', 'nowrap' => true],
        ],
        'actions' => [
            [
                'label' => 'Confirm',
                'icon' => 'bx-check',
                'action' => 'confirm',
                'class' => 'text-success',
                'visible' => fn(array $row, array $context): bool => $row['status'] === 'requested',
            ],
            [
                'label' => 'Decline',
                'icon' => 'bx-x',
                'action' => 'decline',
                'confirm' => 'Decline this request? The customer will be emailed.',
                'visible' => fn(array $row, array $context): bool => $row['status'] === 'requested',
            ],
            [
                'label' => 'Arrived',
                'icon' => 'bx-log-in',
                'action' => 'seat',
                'visible' => fn(array $row, array $context): bool => $row['status'] === 'confirmed',
            ],
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'event' => 'edit'],
            [
                'label' => 'Cancel reservation',
                'icon' => 'bx-calendar-x',
                'action' => 'cancel',
                'confirm' => 'Cancel this reservation? The customer will be emailed.',
                'visible' => fn(array $row, array $context): bool => \in_array($row['status'], ['requested', 'confirmed'], true),
            ],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete',
                'confirm' => 'Delete this reservation? It can be restored from Deleted Reservations. The customer is not emailed.',
                'danger' => true,
            ],
        ],
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'osmium-reservations-deleted',
        'title' => 'Deleted Reservations',
        'noun' => ['item', 'items'],
        'endpoint' => 'reservations/action/',
        'hideWhenEmpty' => false,
        'headerButton' => [
            'label' => 'Purge All',
            'icon' => 'bx-trash',
            'class' => 'btn-outline-danger',
            'action' => 'purge_deleted',
            'confirm' => 'Permanently delete all deleted reservations? This cannot be undone!',
            'visible' => fn(array $rows, array $context): bool => (bool) $context['isAdmin'],
        ],
        'columns' => [
            $changedByColumn,
            ['key' => 'name', 'label' => 'Reservation', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'deletedDate', 'label' => 'Deleted', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ]),

    'tables' => [
        'id' => 'osmium-reservations-tables',
        'title' => 'Tables',
        'noun' => ['table', 'tables'],
        'endpoint' => 'reservations/action/',
        'subtitle' => 'A booking is given the smallest free table with enough seats. Customers never choose or see a table.',
        'emptyText' => 'No tables yet - use Add to create the first.',
        'headerButton' => ['label' => 'Add', 'icon' => 'bx-plus', 'class' => 'btn-primary btn-sm', 'event' => 'add-table'],
        'order' => null,
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'seats', 'label' => 'Seats', 'type' => 'text', 'nowrap' => true],
            ['key' => 'enabledBadge', 'label' => 'On', 'type' => 'raw', 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'event' => 'edit-table'],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete_table',
                'confirm' => 'Delete this table? (Not possible once a reservation uses it: turn it off instead.)',
                'danger' => true,
            ],
        ],
    ],
];
