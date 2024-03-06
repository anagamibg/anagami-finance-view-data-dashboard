@php
    $sourceIcons = [
        'excel' => 'file-excel',
        'csv' => 'file-csv',
        'google_sheets' => 'table',
        'api' => 'plug',
    ];
    $icon = $sourceIcons[$source] ?? 'database';
@endphp
<span class="dbk-chip dbk-data-source is-{{ $source }}">
    <i class="fa fa-{{ $icon }}" aria-hidden="true"></i>{{ __('databackd-data-common.sources.' . $source) }}
</span>
