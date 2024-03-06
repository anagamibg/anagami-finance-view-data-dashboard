@extends('layouts.app')

@section('content')
    @php
        $fmtInt = fn($n) => number_format((int)$n, 0, ',', ' ');
    @endphp
    <!-- CONTENT WRAPPER START -->
    <div class="content-wrapper dbk-data-page">

        <!-- ACTION BAR START -->
        {{-- the page title "Import № N" is already in the top bar; the header carries status + actions --}}
        <x-dbk.page-header>
            <div class="dbk-data-header-meta mt-0">
                @include('databackd.data.batches._status', ['status' => $batch->status])
                @include('databackd.data.batches._source', ['source' => $batch->source])
            </div>
            <x-slot name="actions">
                <x-forms.link-secondary :link="route('databackd.data.batches.index')" icon="arrow-left">
                    @lang('databackd-data-batches.actions.back')
                </x-forms.link-secondary>
                @if ($entriesUrl)
                    <x-forms.link-secondary :link="$entriesUrl" icon="list">
                        @lang('databackd-data-batches.actions.view_entries')
                    </x-forms.link-secondary>
                @endif
                @if ($canUndo)
                    <x-forms.button-primary id="undo-batch" icon="undo">
                        @lang('databackd-data-batches.actions.undo')
                    </x-forms.button-primary>
                @endif
            </x-slot>
        </x-dbk.page-header>
        <!-- ACTION BAR END -->

        @if ($batch->status === 'undone')
            <div class="alert alert-secondary f-14"><i class="fa fa-info-circle mr-2"></i>@lang('databackd-data-batches.undo.undone_note')</div>
        @elseif (in_array($batch->status, ['processing', 'pending']))
            <div class="alert alert-info f-14"><i class="fa fa-spinner mr-2"></i>@lang('databackd-data-batches.undo.processing_note')</div>
        @endif

        <!-- COUNTERS START -->
        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="__('databackd-data-batches.counters.total')" :value="$fmtInt($batch->rows_total)" icon="bi-list-ol" />
            <x-dbk.kpi :label="__('databackd-data-batches.counters.imported')" :value="$fmtInt($batch->rows_imported)" icon="bi-check-circle" tone="revenue" />
            <x-dbk.kpi :label="__('databackd-data-batches.counters.failed')" :value="$fmtInt($batch->rows_failed)" icon="bi-exclamation-triangle" :tone="$batch->rows_failed > 0 ? 'expense' : null" />
            <x-dbk.kpi :label="__('databackd-data-batches.counters.entries_now')" :value="$fmtInt($entriesCount)" icon="bi-journal-text" />
        </div>
        <!-- COUNTERS END -->

        <div class="dbk-grid dbk-data-batch-grid">
            <!-- META START -->
            <x-dbk.card :title="__('databackd-data-batches.meta.title')" class="dbk-span-5">
                <dl class="dbk-dl">
                    <dt>@lang('databackd-data-batches.meta.source')</dt>
                    <dd>@include('databackd.data.batches._source', ['source' => $batch->source])</dd>
                    @if ($batch->file_name)
                        <dt>@lang('databackd-data-batches.meta.file')</dt>
                        <dd class="text-break"><i class="fa fa-file-alt dbk-text-muted mr-1"></i>{{ $batch->file_name }}</dd>
                    @endif
                    @if ($batch->source_url)
                        <dt>@lang('databackd-data-batches.meta.url')</dt>
                        <dd class="text-break"><i class="fa fa-link dbk-text-muted mr-1"></i>{{ $batch->source_url }}</dd>
                    @endif
                    <dt>@lang('databackd-data-batches.meta.user')</dt>
                    <dd>
                        @if ($batch->source === 'api')
                            <i class="fa fa-key dbk-text-muted mr-1"></i>@lang('databackd-data-batches.api_token')
                        @elseif ($importUser)
                            {{ $importUser->name }}
                        @else
                            @lang('databackd-data-batches.system')
                        @endif
                    </dd>
                    <dt>@lang('databackd-data-batches.meta.created_at')</dt>
                    <dd>{{ $batch->created_at?->timezone(company()->timezone)->translatedFormat(company()->date_format . ' H:i') }}</dd>
                    <dt>@lang('databackd-data-batches.meta.finished_at')</dt>
                    <dd>{{ $batch->updated_at?->timezone(company()->timezone)->translatedFormat(company()->date_format . ' H:i') }}</dd>
                    <dt>@lang('databackd-data-batches.meta.status')</dt>
                    <dd>@include('databackd.data.batches._status', ['status' => $batch->status])</dd>
                </dl>

                <h4 class="dbk-data-subhead">@lang('databackd-data-batches.meta.mapping')</h4>
                @if (count($mappingRows))
                    <ul class="dbk-data-mapping-list">
                        @foreach ($mappingRows as $row)
                            <li>
                                <span class="dbk-data-mapping-col">
                                    {{ $row['column'] }}
                                    @if (!empty($row['header']))
                                        <span class="d-block dbk-text-2">„{{ \Illuminate\Support\Str::limit($row['header'], 40) }}“</span>
                                    @endif
                                </span>
                                <i class="fa fa-long-arrow-alt-right" aria-hidden="true"></i>
                                <span class="dbk-data-mapping-field">{{ $row['label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @elseif (in_array($batch->source, ['mt940', 'camt053']))
                    <p class="dbk-text-muted f-13 mb-0">@lang('databackd-data-batches.meta.mapping_bank')</p>
                @else
                    <p class="dbk-text-muted f-13 mb-0">@lang('databackd-data-batches.meta.mapping_empty')</p>
                @endif
            </x-dbk.card>
            <!-- META END -->

            <!-- ERRORS START -->
            <x-dbk.card :title="__('databackd-data-batches.errors.title')" class="dbk-span-7" body-class="p-0">
                @if ($errorsTotal > 0)
                    <x-slot name="actions">
                        <span class="dbk-badge is-bad">{{ $fmtInt($errorsTotal) }}</span>
                        <a href="{{ route('databackd.data.batches.errors-export', $batch->id) }}" class="btn btn-secondary btn-sm rounded f-13">
                            <i class="fa fa-download mr-1"></i>@lang('databackd-data-batches.actions.export_errors')
                        </a>
                    </x-slot>
                @endif

                @if ($errorsTotal > 0)
                    <div class="dbk-table-wrap is-flush batch-errors-scroll">
                        <table class="dbk-table dbk-table--compact dbk-table--sticky">
                            <thead>
                                <tr>
                                    <th class="num" style="width: 90px;">@lang('databackd-data-batches.errors.row')</th>
                                    <th>@lang('databackd-data-batches.errors.reasons')</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($errorRows as $error)
                                    <tr>
                                        <td class="num f-w-500">{{ $error['row'] }}</td>
                                        <td>
                                            <ul class="dbk-data-error-list">
                                                @foreach ((array)($error['messages'] ?? []) as $message)
                                                    <li>{{ $message }}</li>
                                                @endforeach
                                            </ul>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="dbk-data-card-note">
                        @if ($errorsTotal > $errorRows->count())
                            @lang('databackd-data-batches.errors.truncated', ['shown' => $errorRows->count(), 'total' => $errorsTotal])
                        @endif
                        @lang('databackd-data-batches.errors.stored_note')
                    </div>
                @else
                    <x-dbk.empty icon="check-circle" :title="__('databackd-data-batches.errors.empty_title')" :text="__('databackd-data-batches.errors.empty_text')" size="sm" />
                @endif
            </x-dbk.card>
            <!-- ERRORS END -->

            <!-- SAMPLE ENTRIES START -->
            <x-dbk.card :title="__('databackd-data-batches.sample.title')" class="dbk-span-12" body-class="p-0">
                @if ($sampleEntries->count())
                    <div class="dbk-table-wrap is-flush">
                        <table class="dbk-table dbk-table--compact">
                            <thead>
                                <tr>
                                    <th>@lang('databackd-data-batches.sample.date')</th>
                                    <th>@lang('databackd-data-batches.sample.account')</th>
                                    <th>@lang('databackd-data-batches.sample.entity')</th>
                                    <th class="num">@lang('databackd-data-batches.sample.amount')</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sampleEntries as $entry)
                                    <tr>
                                        <td class="text-nowrap">{{ $entry->entry_date?->translatedFormat(company()->date_format) }}</td>
                                        <td>
                                            <span class="dbk-data-code">{{ $entry->account?->code }}</span>
                                            <span class="dbk-text-2">{{ $entry->account?->name }}</span>
                                        </td>
                                        <td class="dbk-text-2">{{ $entry->entity?->name ?? '--' }}</td>
                                        <td class="num f-w-500">{{ number_format((float)$entry->amount, 2, ',', ' ') }} {{ $entry->currency }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($entriesCount > $sampleEntries->count())
                        <div class="dbk-data-card-note">
                            @lang('databackd-data-batches.sample.showing', ['shown' => $sampleEntries->count(), 'total' => $entriesCount])
                        </div>
                    @endif
                @else
                    <x-dbk.empty icon="inbox" :title="__('databackd-data-batches.sample.empty_title')" :text="__('databackd-data-batches.sample.empty_text')" size="sm" />
                @endif
            </x-dbk.card>
            <!-- SAMPLE ENTRIES END -->
        </div>
    </div>
    <!-- CONTENT WRAPPER END -->
@endsection

@push('scripts')
    @if ($canUndo)
        @include('databackd.data.batches._undo_script', ['selector' => '#undo-batch', 'batchId' => $batch->id, 'entries' => $entriesCount])
    @endif
@endpush
