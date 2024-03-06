@php
    $statusClass = [
        'completed' => 'is-good',
        'partial' => 'is-warning',
        'failed' => 'is-bad',
        'undone' => '',
        'processing' => 'is-info',
        'pending' => 'is-info',
    ][$status] ?? '';
@endphp
<span class="dbk-badge {{ $statusClass }}">{{ __('databackd-data-common.statuses.' . $status) }}</span>
