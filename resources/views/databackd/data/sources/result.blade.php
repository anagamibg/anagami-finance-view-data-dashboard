@extends('layouts.app')

@section('content')
    <div class="content-wrapper dbk-data-page dbk-data-sources">
        @php
            $statusClass = ['completed' => 'is-good', 'partial' => 'is-warning', 'failed' => 'is-bad', 'undone' => '', 'processing' => 'is-info', 'pending' => 'is-info'][$batch->status] ?? '';
        @endphp

        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="__('databackd-data-sources.result.total')" :value="number_format($batch->rows_total, 0, ',', ' ')" icon="bi-list-ol" />
            <x-dbk.kpi :label="__('databackd-data-sources.result.imported')" :value="number_format($batch->rows_imported, 0, ',', ' ')" icon="bi-check-circle" tone="revenue" />
            <x-dbk.kpi :label="__('databackd-data-sources.result.failed')" :value="number_format($batch->rows_failed, 0, ',', ' ')" icon="bi-exclamation-triangle" :tone="$batch->rows_failed > 0 ? 'expense' : null" />
            <x-dbk.kpi :label="__('databackd-data-sources.result.status')" :value="$statusLabel" icon="bi-info-circle" />
        </div>

        <x-dbk.card :title="__('databackd-data-sources.result.title', ['id' => $batch->id])">
            <p class="f-14 dbk-text-2 mb-1">
                <span class="dbk-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                <span class="ml-2">{{ __('databackd-data-common.sources.' . $batch->source) }}
                    @if ($batch->file_name) · {{ $batch->file_name }} @endif
                    · {{ $batch->created_at?->timezone(company()->timezone)->translatedFormat('d.m.Y H:i') }}</span>
            </p>
            @if ($batch->source_url)
                <p class="f-13 dbk-text-muted mb-3 text-break">{{ \Illuminate\Support\Str::limit($batch->source_url, 110) }}</p>
            @endif

            @if ($batch->status === 'failed')
                <div class="alert alert-danger f-14">@lang('databackd-data-sources.result.nothing_imported')</div>
            @elseif ($batch->rows_failed > 0)
                <div class="alert alert-warning f-14">@lang('databackd-data-sources.result.partial', ['failed' => $batch->rows_failed])</div>
            @elseif ($batch->status === 'completed')
                <div class="alert alert-success f-14">@lang('databackd-data-sources.result.success', ['count' => $batch->rows_imported])</div>
            @endif

            @if ($importErrors)
                <div class="fin-import-errors-head">
                    <h4 class="dbk-data-subhead">@lang('databackd-data-sources.result.errors_title')</h4>
                    @if (Route::has('databackd.data.batches.errors-export') && databackd_module('databackd_data')) {{-- audit 2026-10-30 (G11) --}}
                        {{-- AFV (S12): the failed rows with their original cells --}}
                        <a href="{{ route('databackd.data.batches.errors-export', $batch->id) }}" class="btn btn-secondary btn-sm rounded f-13">
                            <i class="fa fa-download mr-1" aria-hidden="true"></i>@lang('databackd-data-import.result.errors_download')
                        </a>
                    @endif
                </div>
                <div class="dbk-table-wrap dbk-data-errors-wrap">
                    <table class="dbk-table dbk-table--compact dbk-table--sticky">
                        <thead>
                        <tr>
                            <th class="num" style="width: 90px">@lang('databackd-data-sources.preview.row')</th>
                            <th>@lang('databackd-data-sources.result.problem')</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($importErrors as $error)
                            <tr>
                                <td class="num f-w-500">{{ $error['row'] ?? '' }}</td>
                                <td class="dbk-text-bad">{{ implode(' ', (array)($error['messages'] ?? [])) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($errorsTotal > count($importErrors))
                    <p class="f-12 dbk-text-muted mt-2">@lang('databackd-data-sources.result.errors_more', ['count' => $errorsTotal - count($importErrors)])</p>
                @endif
            @endif

            <div class="dbk-data-form-actions mt-4">
                <a href="{{ route('databackd.data.sources.index') }}" class="btn btn-primary rounded f-14 p-2">
                    <i class="fa fa-table mr-1"></i> @lang('databackd-data-sources.result.back')
                </a>
                @if (Route::has('databackd.data.batches.show') && databackd_module('databackd_data')) {{-- audit 2026-10-30 (G11) --}}
                    <a href="{{ route('databackd.data.batches.show', $batch->id) }}" class="btn btn-secondary rounded f-14 p-2 ">
                        <i class="fa fa-history mr-1"></i> @lang('databackd-data-sources.result.history')
                    </a>
                @endif
                @if (Route::has('databackd.data.entries.index') && databackd_module('databackd_data')) {{-- audit 2026-10-30 (G11) --}}
                    <a href="{{ route('databackd.data.entries.index') }}" class="btn btn-secondary rounded f-14 p-2 ">
                        <i class="fa fa-list mr-1"></i> @lang('databackd-data-sources.result.entries')
                    </a>
                @endif
            </div>
        </x-dbk.card>
    </div>
@endsection
