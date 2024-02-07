{{--
    Row-by-row validation preview (rendered by the preview endpoint, inserted into the mapping partial).
    Variables: $preview (FinEntryImporter::preview() result), $mapping, $missing (field ids), $fields, $headers, $count (rows shown/available),
    $duplicates (optional, FinEntryImporter::existingMatches() result - Anagami Finance View S12)
--}}
@php
    $fieldNames = collect($fields)->pluck('name', 'id');
    $mappedFields = array_values(array_filter($fields, fn($f) => isset($mapping[$f['id']])));
    $valid = collect($preview)->filter(fn($r) => empty($r['errors']))->count();
    $invalid = count($preview) - $valid;
@endphp

<h4 class="dbk-data-subhead">{{ __('databackd-data-import.preview.title', ['count' => $count]) }}</h4>

@if (!$missing && !empty($duplicates['found']))
    {{-- AFV (S12): the data is already in the entries - importing it again doubles the figures --}}
    <div class="alert alert-warning f-14 fin-import-duplicate" role="alert">
        <i class="fa fa-clone mr-1" aria-hidden="true"></i>
        {{ __('databackd-data-import.preview.duplicates', ['found' => $duplicates['found'], 'checked' => $duplicates['checked']]) }}
        @if (!empty($duplicates['batches']))
            <span class="d-block mt-1">
                {{ __('databackd-data-import.preview.duplicates_batches') }}
                @foreach ($duplicates['batches'] as $dupBatch)
                    @if (Route::has('databackd.data.batches.show'))
                        <a href="{{ route('databackd.data.batches.show', $dupBatch['id']) }}" class="f-w-500">{{ __('databackd-data-import.preview.duplicates_batch', ['id' => $dupBatch['id'], 'date' => $dupBatch['created_at']]) }}</a>@if (!$loop->last), @endif
                    @else
                        {{ __('databackd-data-import.preview.duplicates_batch', ['id' => $dupBatch['id'], 'date' => $dupBatch['created_at']]) }}@if (!$loop->last), @endif
                    @endif
                @endforeach
            </span>
        @else
            <span class="d-block mt-1">{{ __('databackd-data-import.preview.duplicates_manual') }}</span>
        @endif
    </div>
@endif

@if ($missing)
    <div class="alert alert-warning mb-0">
        {{ __('databackd-data-import.preview.missing', ['fields' => collect($missing)->map(fn($id) => $fieldNames[$id] ?? $id)->implode(', ')]) }}
    </div>
@elseif (!$preview)
    <div class="alert alert-info mb-0">{{ __('databackd-data-import.preview.no_rows') }}</div>
@else
    <div class="fin-prev-summary">
        <span class="dbk-badge is-good">{{ $valid }} {{ __('databackd-data-import.preview.valid') }}</span>
        <span class="dbk-badge {{ $invalid ? 'is-bad' : '' }}">{{ $invalid }} {{ __('databackd-data-import.preview.invalid') }}</span>
        <span class="dbk-text-muted">{{ __('databackd-data-import.preview.legend') }}</span>
    </div>
    <div class="dbk-table-wrap">
        <table class="dbk-table dbk-table--compact fin-prev-table">
            <thead>
                <tr>
                    <th class="num">{{ __('databackd-data-import.preview.row') }}</th>
                    <th>{{ __('databackd-data-import.preview.status') }}</th>
                    @foreach ($mappedFields as $field)
                        <th>{{ $field['name'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($preview as $row)
                    @php $ok = empty($row['errors']); @endphp
                    <tr class="{{ $ok ? 'fin-prev-ok' : 'fin-prev-bad' }}">
                        <td class="num dbk-text-muted">{{ $row['row'] }}</td>
                        <td style="min-width: 190px;">
                            @if ($ok)
                                <span class="dbk-badge is-good">{{ __('databackd-data-import.preview.valid') }}</span>
                                @foreach (($row['data']['_new_dimensions'] ?? []) as $dimension)
                                    <div class="dbk-text-warning f-12 mt-1">
                                        {{ __('databackd-data-import.preview.new_dimension', ['type' => __('databackd-data-import.preview.dimension_types.' . $dimension['type']), 'name' => $dimension['name']]) }}
                                    </div>
                                @endforeach
                            @else
                                <span class="dbk-badge is-bad">{{ __('databackd-data-import.preview.invalid') }}</span>
                                <ul class="fin-prev-errors">
                                    @foreach ($row['errors'] as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        @foreach ($mappedFields as $field)
                            @php
                                $value = $row['raw'][$mapping[$field['id']]] ?? '';

                                if ($row['data'] !== null && $field['id'] === 'entry_date' && !empty($row['data']['entry_date'])) {
                                    $value = \Carbon\Carbon::parse($row['data']['entry_date'])->format('d.m.Y');
                                }
                                elseif ($row['data'] !== null && $field['id'] === 'amount' && isset($row['data']['amount'])) {
                                    $value = number_format((float)$row['data']['amount'], 2, ',', ' ');
                                }
                            @endphp
                            <td>{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
