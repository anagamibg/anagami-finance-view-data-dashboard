@extends('layouts.app')

@section('content')
    <div class="content-wrapper dbk-data-page dbk-data-sources">
        @include('databackd.data.sources._alerts')

        <div class="dbk-grid">
            <!-- NEW IMPORT -->
            <div class="dbk-span-4 dbk-data-stack">
                <x-dbk.card :title="__('databackd-data-sources.new.title')" :subtitle="__('databackd-data-sources.new.help')">

                    <form method="POST" action="{{ route('databackd.data.sources.fetch') }}" id="fetch-form" autocomplete="off">
                        @csrf

                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="src-type-option d-block mb-0 {{ old('type', 'google_sheets') === 'google_sheets' ? 'active' : '' }}">
                                    <input type="radio" name="type" value="google_sheets" class="mr-1" @checked(old('type', 'google_sheets') === 'google_sheets')>
                                    <i class="fa fa-table src-type-icon is-sheets" aria-hidden="true"></i>
                                    <span class="f-14 f-w-500">@lang('databackd-data-common.source_types.google_sheets')</span>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="src-type-option d-block mb-0 {{ old('type') === 'csv_url' ? 'active' : '' }}">
                                    <input type="radio" name="type" value="csv_url" class="mr-1" @checked(old('type') === 'csv_url')>
                                    <i class="fa fa-file-csv src-type-icon is-csv" aria-hidden="true"></i>
                                    <span class="f-14 f-w-500">@lang('databackd-data-common.source_types.csv_url')</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="f-14 text-dark-grey" for="src-url">@lang('databackd-data-sources.form.url') <sup class="text-red">*</sup></label>
                            <input type="text" class="form-control height-35 f-14 @error('url') is-invalid @enderror" name="url" id="src-url" required
                                   value="{{ old('url') }}" placeholder="https://docs.google.com/spreadsheets/d/…/edit#gid=0"
                                   aria-describedby="src-url-help" @error('url') aria-invalid="true" @enderror>
                            @error('url')
                                <div class="invalid-feedback d-block" role="alert">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted" id="src-url-help"></small>
                        </div>

                        <div class="row">
                            <div class="col-md-5 form-group" id="sheet-group">
                                <label class="f-14 text-dark-grey" for="src-sheet">@lang('databackd-data-sources.form.sheet')</label>
                                <input type="text" class="form-control height-35 f-14" name="sheet" id="src-sheet"
                                       value="{{ old('sheet') }}" placeholder="0">
                                <small class="form-text text-muted">@lang('databackd-data-sources.form.sheet_help')</small>
                            </div>
                            <div class="col-md-7 form-group">
                                <label class="f-14 text-dark-grey" for="src-entity">@lang('databackd-data-sources.form.entity')</label>
                                <select name="default_entity_id" id="src-entity" class="form-control select-picker" data-size="8">
                                    <option value="">@lang('databackd-data-sources.form.entity_none')</option>
                                    @foreach ($entities as $entity)
                                        <option value="{{ $entity->id }}" @selected(old('default_entity_id') == $entity->id)>{{ $entity->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="remember" id="src-remember" value="1" @checked(old('remember'))>
                                <label class="form-check-label f-14 text-dark-grey" for="src-remember">@lang('databackd-data-sources.form.remember')</label>
                            </div>
                        </div>

                        <div class="form-group" id="name-group" style="{{ old('remember') ? '' : 'display:none' }}">
                            <label class="f-14 text-dark-grey" for="src-name">@lang('databackd-data-sources.form.name')</label>
                            <input type="text" class="form-control height-35 f-14" name="name" id="src-name" maxlength="150"
                                   value="{{ old('name') }}" placeholder="@lang('databackd-data-sources.form.name_placeholder')">
                        </div>

                        <div class="dbk-data-form-actions">
                        <button type="submit" class="btn btn-primary rounded f-14 p-2" id="fetch-btn">
                            <i class="fa fa-cloud-download-alt mr-1"></i> @lang('databackd-data-sources.form.fetch')
                        </button>
                        @if (Route::has('databackd.data.import.index') && databackd_module('databackd_data')) {{-- audit 2026-10-30 (G11) --}}
                            <a href="{{ route('databackd.data.import.index') }}" class="btn btn-secondary rounded f-14 p-2">
                                <i class="fa fa-file-excel mr-1"></i> @lang('databackd-data-sources.form.upload_file')
                            </a>
                        @endif
                        </div>
                    </form>
                </x-dbk.card>

                <x-dbk.card :title="__('databackd-data-sources.sharing.title')" :subtitle="__('databackd-data-sources.sharing.intro')">
                    <ol class="dbk-data-howto src-steps">
                        <li>@lang('databackd-data-sources.sharing.step1')</li>
                        <li>@lang('databackd-data-sources.sharing.step2')</li>
                        <li>@lang('databackd-data-sources.sharing.step3')</li>
                        <li>@lang('databackd-data-sources.sharing.step4')</li>
                    </ol>
                    <p class="f-13 text-lightest mb-0">@lang('databackd-data-sources.sharing.columns')</p>
                    @if ($isLocal)
                        <p class="f-13 text-lightest mt-2 mb-0">
                            <i class="fa fa-info-circle mr-1"></i>@lang('databackd-data-sources.sharing.local_demo', ['path' => $templateUrl])
                        </p>
                    @endif
                </x-dbk.card>
            </div>

            <!-- SAVED SOURCES -->
            <div class="dbk-span-8">
                <x-dbk.card :title="__('databackd-data-sources.list.title')" body-class="p-0">
                    @if ($sources->isEmpty())
                        <x-dbk.empty icon="table" :title="__('databackd-data-sources.list.empty')" :text="__('databackd-data-sources.list.empty_help')" />
                    @else
                        <div class="dbk-table-wrap is-flush">
                            <table class="dbk-table src-table">
                                <thead>
                                <tr>
                                    <th>@lang('databackd-data-sources.list.name')</th>
                                    <th>@lang('databackd-data-sources.list.url')</th>
                                    <th>@lang('databackd-data-sources.list.entity')</th>
                                    <th>@lang('databackd-data-sources.list.last_sync')</th>
                                    <th class="text-right">@lang('databackd-data-sources.list.actions')</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($sources as $source)
                                    <tr>
                                        <td class="f-14">
                                            <span class="src-name">{{ $source->name }}</span>
                                            <span class="dbk-chip dbk-data-source is-{{ $source->type === 'google_sheets' ? 'google_sheets' : 'csv' }}">
                                                <i class="fa fa-{{ $source->type === 'google_sheets' ? 'table' : 'file-csv' }}" aria-hidden="true"></i>
                                                {{ __('databackd-data-common.source_types.' . $source->type) }}
                                            </span>
                                            @if ($source->sheet !== null && $source->sheet !== '')
                                                <span class="f-12 text-lightest ml-1">@lang('databackd-data-sources.list.sheet') {{ $source->sheet }}</span>
                                            @endif
                                        </td>
                                        <td class="f-13">
                                            @if ($source->url)
                                                <span class="src-url text-dark-grey" title="{{ $source->url }}">{{ \Illuminate\Support\Str::limit(preg_replace('~^https?://~', '', $source->url), 38) }}</span>
                                            @else
                                                <span class="text-lightest">—</span>
                                            @endif
                                        </td>
                                        <td class="f-13 text-dark-grey">{{ $source->defaultEntity->name ?? '—' }}</td>
                                        <td class="f-13 text-dark-grey">
                                            @if ($source->last_synced_at)
                                                {{ $source->last_synced_at->timezone(company()->timezone)->translatedFormat('d.m.Y H:i') }}
                                                @if ($source->lastBatch)
                                                    <br>
                                                    @if (Route::has('databackd.data.batches.show') && databackd_module('databackd_data')) {{-- audit 2026-10-30 (G11) --}}
                                                        <a href="{{ route('databackd.data.batches.show', $source->lastBatch->id) }}" class="f-12">
                                                            #{{ $source->lastBatch->id }} · {{ __('databackd-data-common.statuses.' . $source->lastBatch->status) }}
                                                        </a>
                                                    @else
                                                        <a href="{{ route('databackd.data.sources.result', $source->lastBatch->id) }}" class="f-12">
                                                            #{{ $source->lastBatch->id }} · {{ __('databackd-data-common.statuses.' . $source->lastBatch->status) }}
                                                        </a>
                                                    @endif
                                                    <span class="f-12 text-lightest">· {{ $source->lastBatch->rows_imported }}/{{ $source->lastBatch->rows_total }}</span>
                                                @endif
                                            @else
                                                <span class="text-lightest">@lang('databackd-data-sources.list.never')</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            <form method="POST" action="{{ route('databackd.data.sources.sync', $source->id) }}" class="d-inline sync-form"
                                                  data-name="{{ $source->name }}"
                                                  data-replaces="{{ $source->lastBatch && $source->lastBatch->status !== 'undone' ? $source->lastBatch->rows_imported : 0 }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-primary f-13" title="@lang('databackd-data-sources.list.sync_help')">
                                                    <i class="fa fa-sync-alt mr-1"></i>@lang('databackd-data-sources.list.sync')
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('databackd.data.sources.destroy', $source->id) }}" class="d-inline delete-form">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-light f-13 text-danger" title="@lang('databackd-data-sources.list.delete')" aria-label="@lang('databackd-data-sources.list.delete') {{ $source->name }}">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="dbk-data-card-note">
                            <i class="fa fa-info-circle mr-1"></i>@lang('databackd-data-sources.list.sync_note')
                        </div>
                    @endif
                </x-dbk.card>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var help = {
                google_sheets: @json(__('databackd-data-sources.form.url_help_sheets')),
                csv_url: @json(__('databackd-data-sources.form.url_help_csv'))
            };
            var placeholders = {
                google_sheets: 'https://docs.google.com/spreadsheets/d/…/edit#gid=0',
                csv_url: @json($templateUrl ?: 'https://example.com/data.csv')
            };

            function refreshType() {
                var type = $('input[name=type]:checked').val();
                $('.src-type-option').removeClass('active');
                $('input[name=type]:checked').closest('.src-type-option').addClass('active');
                $('#src-url-help').text(help[type]);
                $('#src-url').attr('placeholder', placeholders[type]);
                $('#sheet-group').toggle(type === 'google_sheets');
            }

            $('input[name=type]').on('change', refreshType);
            refreshType();

            $('#src-remember').on('change', function () {
                $('#name-group').toggle(this.checked);
            });

            $('#fetch-form').on('submit', function () {
                $('#fetch-btn').prop('disabled', true).find('i').attr('class', 'fa fa-spinner fa-spin mr-1');
            });

            // AFV (S12): a synchronization replaces the entries of the previous import - say so before it runs
            $('.sync-form').on('submit', function (e) {
                var form = this;

                if ($(form).data('confirmed')) {
                    $(form).find('button').prop('disabled', true).find('i').addClass('fa-spin');
                    return;
                }

                e.preventDefault();
                var replaces = parseInt($(form).data('replaces'), 10) || 0;
                var text = replaces > 0
                    ? @json(__('databackd-data-sources.list.sync_confirm_replace', ['count' => ':count'])).replace(':count', String(replaces).replace(/\B(?=(\d{3})+(?!\d))/g, ' '))
                    : @json(__('databackd-data-sources.list.sync_confirm_new'));

                Swal.fire({
                    title: @json(__('databackd-data-sources.list.sync_confirm_title', ['name' => ':name'])).replace(':name', $(form).data('name')),
                    text: text,
                    icon: 'question',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: @json(__('databackd-data-sources.list.sync')),
                    cancelButtonText: @json(__('databackd-data-sources.list.cancel')),
                    customClass: {confirmButton: 'btn btn-primary mr-3', cancelButton: 'btn btn-secondary'},
                    showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.isConfirmed) {
                        $(form).data('confirmed', true);
                        $(form).find('button').prop('disabled', true).find('i').addClass('fa-spin');
                        form.submit();
                    }
                });
            });

            $('.delete-form').on('submit', function (e) {
                e.preventDefault();
                var form = this;
                Swal.fire({
                    title: @json(__('databackd-data-sources.list.delete_title')),
                    text: @json(__('databackd-data-sources.list.delete_text')),
                    icon: 'warning',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: @json(__('databackd-data-sources.list.delete')),
                    cancelButtonText: @json(__('databackd-data-sources.list.cancel')),
                    customClass: {confirmButton: 'btn btn-primary mr-3', cancelButton: 'btn btn-secondary'},
                    showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });
    </script>
@endpush
