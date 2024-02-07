@extends('layouts.app')

@php
    $processed = $batch->rows_imported + $batch->rows_failed;
    $percent = $batch->rows_total > 0 ? min(99, (int)floor($processed / $batch->rows_total * 100)) : 0;
@endphp

@section('content')
    <div class="content-wrapper dbk-data-page dbk-data-import">
        @include('databackd.data.import._steps', ['step' => $running ? 3 : 4])

        @if ($running)
            <x-dbk.card :title="__('databackd-data-import.result.running_title')">
                <p class="mb-2 f-14" id="fin-progress-text">
                    {{ __('databackd-data-import.result.running_text', ['done' => $processed, 'total' => $batch->rows_total]) }}
                </p>
                <div class="progress dbk-data-progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="fin-progress-bar" role="progressbar"
                         style="width: {{ $percent }}%;" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">{{ $percent }}%</div>
                </div>
                <p class="dbk-text-muted f-12 mt-2 mb-0">{{ __('databackd-data-import.result.running_hint') }}</p>
                <p class="dbk-text-warning f-12 mt-2 mb-0 d-none" id="fin-progress-lost">{{ __('databackd-data-import.result.connection_lost') }}</p>
            </x-dbk.card>
        @else
            <div class="dbk-kpi-grid dbk-kpi-grid--3">
                <x-dbk.kpi :label="__('databackd-data-import.result.imported')" :value="number_format($batch->rows_imported, 0, ',', ' ')" icon="bi-check-circle" tone="revenue" />
                <x-dbk.kpi :label="__('databackd-data-import.result.failed')" :value="number_format($batch->rows_failed, 0, ',', ' ')" icon="bi-exclamation-triangle" :tone="$batch->rows_failed ? 'expense' : null" />
                <x-dbk.kpi :label="__('databackd-data-import.result.total')" :value="number_format($batch->rows_total, 0, ',', ' ')" icon="bi-list-ol" />
            </div>

            <x-dbk.card :title="__('databackd-data-import.result.title')" class="mb-4">
                @if ($batch->status === 'undone')
                    <div class="alert alert-secondary">{{ __('databackd-data-import.result.undone') }}</div>
                @elseif ($batch->status === 'completed')
                    <div class="alert alert-success">{{ __('databackd-data-import.result.completed_ok') }}</div>
                @elseif ($batch->status === 'partial')
                    <div class="alert alert-warning">{{ __('databackd-data-import.result.completed_partial') }}</div>
                @else
                    <div class="alert alert-danger">{{ __('databackd-data-import.result.completed_failed') }}</div>
                @endif

                <dl class="dbk-dl dbk-data-result-meta">
                    <dt>{{ __('databackd-data-import.result.status') }}</dt>
                    <dd>@include('databackd.data.batches._status', ['status' => $batch->status])</dd>
                    <dt>{{ __('databackd-data-import.result.file') }}</dt>
                    <dd>
                        <span class="f-w-500 text-break">{{ $batch->file_name }}</span>
                        @include('databackd.data.batches._source', ['source' => $batch->source])
                    </dd>
                    <dt>{{ __('databackd-data-import.result.created_at') }}</dt>
                    <dd>{{ $batch->created_at ? $batch->created_at->timezone(company()->timezone)->format('d.m.Y H:i') : '' }}</dd>
                </dl>

                @if ($errorRows->isNotEmpty())
                    <div class="fin-import-errors-head">
                        <h4 class="dbk-data-subhead">{{ __('databackd-data-import.result.errors_title') }}</h4>
                        @if (Route::has('databackd.data.batches.errors-export'))
                            {{-- AFV (S12): the failed rows with their original cells, to fix and import again --}}
                            <a href="{{ route('databackd.data.batches.errors-export', $batch->id) }}" class="btn btn-secondary btn-sm rounded f-13" title="{{ __('databackd-data-import.result.errors_download_help') }}">
                                <i class="fa fa-download mr-1" aria-hidden="true"></i>{{ __('databackd-data-import.result.errors_download') }}
                            </a>
                        @endif
                    </div>
                    <p class="dbk-text-muted f-12 mb-2">{{ __('databackd-data-import.result.errors_download_help') }}</p>
                    <div class="dbk-table-wrap dbk-data-errors-wrap">
                        <table class="dbk-table dbk-table--compact dbk-table--sticky">
                            <thead>
                                <tr>
                                    <th class="num" style="width: 120px;">{{ __('databackd-data-import.result.errors_row') }}</th>
                                    <th>{{ __('databackd-data-import.result.errors_reasons') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($errorRows as $error)
                                    <tr class="fin-prev-bad">
                                        <td class="num f-w-500">{{ !empty($error['row']) ? $error['row'] : '—' }}</td>
                                        <td>
                                            <ul class="dbk-data-error-list">
                                                @foreach (($error['messages'] ?? []) as $message)
                                                    <li>{{ $message }}</li>
                                                @endforeach
                                            </ul>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($errorCount > $errorsShown)
                        <p class="dbk-text-muted f-12 mt-2 mb-0">{{ __('databackd-data-import.result.errors_shown', ['shown' => $errorsShown, 'total' => $errorCount]) }}</p>
                    @endif
                @endif
            </x-dbk.card>

            <div class="dbk-data-form-actions mb-4">
                <x-forms.link-primary :link="route('databackd.data.import.index')" icon="upload">
                    {{ __('databackd-data-import.result.new_import') }}
                </x-forms.link-primary>
                @if (Route::has('databackd.data.entries.index'))
                    <x-forms.link-secondary :link="route('databackd.data.entries.index')" icon="table">
                        {{ __('databackd-data-import.result.view_entries') }}
                    </x-forms.link-secondary>
                @endif
                @if (Route::has('databackd.data.batches.index'))
                    <x-forms.link-secondary :link="route('databackd.data.batches.index')" icon="history">
                        {{ __('databackd-data-import.result.history') }}
                    </x-forms.link-secondary>
                @endif
                @if (Route::has('databackd.data.batches.show'))
                    <x-forms.link-secondary :link="route('databackd.data.batches.show', $batch->id)" icon="info-circle">
                        {{ __('databackd-data-import.result.details') }}
                    </x-forms.link-secondary>
                @endif
                @if ($canUndo && Route::has('databackd.data.batches.undo'))
                    {{-- AFV (S12): a wrong import can be taken back right away (same confirmation as in the history) --}}
                    <x-forms.button-secondary id="fin-import-undo" icon="undo" class="dbk-data-undo-btn">
                        {{ __('databackd-data-import.result.undo') }}
                    </x-forms.button-secondary>
                @endif
            </div>
        @endif
    </div>
@endsection

@if (!$running && $canUndo && Route::has('databackd.data.batches.undo'))
    @push('scripts')
        @include('databackd.data.batches._undo_script', ['selector' => '#fin-import-undo', 'batchId' => $batch->id, 'entries' => (int)$batch->rows_imported])
    @endpush
@endif

@push('scripts')
    @if ($running)
        <script>
            $(function () {
                var failures = 0;
                @php($runningText = __('databackd-data-import.result.running_text', ['done' => ':done', 'total' => ':total']))
                var runningText = @json($runningText);

                function poll() {
                    $.getJSON("{{ route('databackd.data.import.progress', ['batch' => $batch->id]) }}")
                        .done(function (r) {
                            failures = 0;
                            $('#fin-progress-lost').addClass('d-none');
                            $('#fin-progress-bar').css('width', r.percent + '%').attr('aria-valuenow', r.percent).text(r.percent + '%');
                            $('#fin-progress-text').text(runningText.replace(':done', r.processed).replace(':total', r.rows_total));

                            if (r.finished) {
                                window.location.reload();
                                return;
                            }
                            setTimeout(poll, 1200);
                        })
                        .fail(function () {
                            failures++;
                            $('#fin-progress-lost').removeClass('d-none');
                            if (failures >= 5) { window.location.reload(); return; }
                            setTimeout(poll, 3000);
                        });
                }

                poll();
            });
        </script>
    @endif
@endpush
