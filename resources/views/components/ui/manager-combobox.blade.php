@props([
    'managers',
    'selected' => null,
    'name' => 'manager_id',
    'id' => 'manager_id',
])

@php
    $managerOptions = $managers->map(function ($mgr): array {
        $mgrName = (string) ($mgr->user?->name ?? '-');
        $mgrPosition = (string) ($mgr->position ?? '');
        $mgrDepartment = (string) ($mgr->department?->name ?? '');
        $sublabel = implode(' • ', array_filter([$mgrPosition, $mgrDepartment], fn ($v) => $v !== ''));

        return [
            'id' => (string) $mgr->id,
            'label' => $mgrName,
            'sublabel' => $sublabel,
            'displayLabel' => $mgrName . ($mgrPosition !== '' ? " ({$mgrPosition})" : ''),
            'searchText' => trim($mgrName . ' ' . $mgrPosition . ' ' . $mgrDepartment),
        ];
    })->values()->all();
@endphp

<x-ui.searchable-select
    :name="$name"
    :id="$id"
    :options="$managerOptions"
    :selected="$selected"
    :placeholder="__('staff.field_manager_none')"
    :searchPlaceholder="__('staff.manager_search_placeholder')"
    :emptyText="__('staff.manager_search_empty')"
    :clearLabel="__('staff.manager_clear')"
    :nullable="true"
    :required="false" />
