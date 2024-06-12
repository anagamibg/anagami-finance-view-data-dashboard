@extends('layouts.app')

@push('styles')
    <style>
        .pv-table td, .pv-table th { font-size: 13px; vertical-align: middle; }
        .pv-table tr.pv-error { background: #fff5f5; }
    </style>
@endpush

@section('content')
    <div class="content-wrapper dbk-data-page dbk-data-sources">
        @include('databackd.data.sources._alerts')

        @if (!$token)
            <div class="dbk-card">
                <x-dbk.empty icon="table" :title="__('databackd-data-sources.mapping.empty')" :text="__('databackd-data-sources.mapping.empty_help')">
                    <a href="{{ route('databackd.data.sources.index') }}" class="btn btn-primary rounded f-14 p-2">
                        <i class="fa fa-arrow-left mr-1"></i> @lang('databackd-data-sources.mapping.start_new')
                    </a>
                </x-dbk.empty>
            </div>
        @else
            @php $sharedMapping = view()->exists('databackd.data.import._mapping') && view()->exists('databackd.data.import._preview'); @endphp
            <form method="POST" action="{{ route('databackd.data.sources.import') }}" id="mapping-form" autocomplete="off">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="dbk-grid mb-4">
                    <div class="dbk-span-8">
                        <x-dbk.card :title="__('databackd-data-sources.mapping.title')" :subtitle="__('databackd-data-sources.mapping.help')">
                            <div>
                                <p class="dbk-data-file-meta">
                                    <span class="dbk-chip dbk-data-source is-{{ $meta['type'] === 'google_sheets' ? 'google_sheets' : 'csv' }}"><i class="fa fa-{{ $meta['type'] === 'google_sheets' ? 'table' : 'file-csv' }}" aria-hidden="true"></i>{{ __('databackd-data-common.source_types.' . $meta['type']) }}</span>
                                    <span class="ml-1" title="{{ $meta['url'] }}">{{ \Illuminate\Support\Str::limit(preg_replace('~^https?://~', '', $meta['url']), 70) }}</span>
                                    · @lang('databackd-data-sources.mapping.rows_found', ['count' => $totalRows, 'columns' => count($headers)])
                                </p>
                            </div>

                            @include(view()->exists('databackd.data.import._mapping') ? 'databackd.data.import._mapping' : 'databackd.data.sources._mapping_fallback', [
                                'headers' => $headers,
                                'rows' => $sampleRows,
                                'fields' => $fields,
                                'mapping' => $mappingData,
                                'formPrefix' => 'columns',
                                'previewUrl' => $sharedMapping ? route('databackd.data.sources.preview') : null,
                            ])
                        </x-dbk.card>
                    </div>

                    <div class="dbk-span-4">
                        <x-dbk.card :title="__('databackd-data-sources.mapping.options')" class="dbk-data-sticky-card">
                            <div class="form-group">
                                <label class="f-14 text-dark-grey" for="map-entity">@lang('databackd-data-sources.form.entity')</label>
                                <select name="entity_id" id="map-entity" class="form-control select-picker" data-size="8">
                                    <option value="">@lang('databackd-data-sources.form.entity_none')</option>
                                    @foreach ($entities as $entity)
                                        <option value="{{ $entity->id }}" @selected(old('entity_id', $meta['entity_id']) == $entity->id)>{{ $entity->name }}</option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">@lang('databackd-data-sources.mapping.entity_help')</small>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="create_missing_dimensions" id="map-create" value="1" @checked(old('create_missing_dimensions'))>
                                <label class="form-check-label f-14 text-dark-grey" for="map-create">@lang('databackd-data-sources.mapping.create_missing')</label>
                                <small class="form-text text-muted">@lang('databackd-data-sources.mapping.create_missing_help')</small>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="remember" id="map-remember" value="1" @checked(old('remember', $meta['remember']))>
                                <label class="form-check-label f-14 text-dark-grey" for="map-remember">@lang('databackd-data-sources.form.remember')</label>
                                <small class="form-text text-muted">@lang('databackd-data-sources.mapping.remember_help')</small>
                                @if (!empty($savedSource))
                                    <small class="form-text text-dark-grey"><i class="fa fa-info-circle mr-1" aria-hidden="true"></i>{{ __('databackd-data-sources.mapping.existing_source', ['name' => $savedSource->name]) }}</small>
                                @endif
                            </div>

                            <div class="form-group" id="map-name-group" style="{{ old('remember', $meta['remember']) ? '' : 'display:none' }}">
                                <label class="f-14 text-dark-grey" for="map-name">@lang('databackd-data-sources.form.name')</label>
                                <input type="text" class="form-control height-35 f-14" name="name" id="map-name" maxlength="150"
                                       value="{{ old('name', $meta['name']) }}" placeholder="{{ $meta['file_name'] }}">
                            </div>

                            <hr>

                            @unless ($sharedMapping)
                                <button type="button" class="btn btn-secondary rounded f-14 p-2 w-100 mb-2" id="preview-btn">
                                    <i class="fa fa-eye mr-1"></i> @lang('databackd-data-sources.mapping.preview')
                                </button>
                            @endunless
                            <button type="submit" class="btn btn-primary rounded f-14 p-2 w-100 mb-2" id="import-btn">
                                <i class="fa fa-check mr-1"></i> @lang('databackd-data-sources.mapping.import', ['count' => $totalRows])
                            </button>
                            <button type="submit" formaction="{{ route('databackd.data.sources.store') }}" class="btn btn-outline-secondary rounded f-14 p-2 w-100 mb-2" id="save-btn">
                                <i class="fa fa-bookmark mr-1"></i> @lang('databackd-data-sources.mapping.save_only')
                            </button>
                            <a href="{{ route('databackd.data.sources.index') }}" class="btn btn-link f-14 w-100 text-dark-grey">
                                @lang('databackd-data-sources.mapping.back')
                            </a>
                        </x-dbk.card>
                    </div>
                </div>

                @unless ($sharedMapping)
                <div id="preview-box" class="mb-4" style="display:none">
                    <x-cards.data :title="__('databackd-data-sources.preview.title')" padding="false">
                        <div id="preview-summary" class="px-3 pt-3 f-14"></div>
                        <div class="table-responsive">
                            <table class="table pv-table mb-0">
                                <thead class="thead-light">
                                <tr>
                                    <th>@lang('databackd-data-sources.preview.row')</th>
                                    <th>@lang('databackd-data-common.fields.entry_date')</th>
                                    <th>@lang('databackd-data-common.fields.account_code')</th>
                                    <th class="text-right">@lang('databackd-data-common.fields.amount')</th>
                                    <th>@lang('databackd-data-common.fields.kind')</th>
                                    <th>@lang('databackd-data-sources.preview.status')</th>
                                </tr>
                                </thead>
                                <tbody id="preview-body"></tbody>
                            </table>
                        </div>
                    </x-cards.data>
                </div>
                @endunless
            </form>
        @endif
    </div>
@endsection

@push('scripts')
    @if ($token)
        <script>
            $(function () {
                var labels = {
                    ok: @json(__('databackd-data-sources.preview.ok')),
                    missing: @json(__('databackd-data-sources.preview.missing')),
                    summary: @json(__('databackd-data-sources.preview.summary')),
                    failed: @json(__('databackd-data-sources.preview.failed'))
                };

                function esc(value) {
                    return $('<div>').text(value === null || value === undefined ? '' : value).html();
                }

                $('#map-remember').on('change', function () {
                    $('#map-name-group').toggle(this.checked);
                });

                $('#preview-btn').on('click', function () {
                    var btn = $(this).prop('disabled', true);
                    btn.find('i').attr('class', 'fa fa-spinner fa-spin mr-1');

                    $.ajax({
                        url: @json(route('databackd.data.sources.preview')),
                        type: 'POST',
                        data: $('#mapping-form').serialize(),
                        success: function (res) {
                            var html = '';

                            if (res.missing.length) {
                                $('#preview-summary').html('<div class="alert alert-warning mb-2">' + esc(labels.missing.replace(':fields', res.missing.join(', '))) + '</div>');
                            } else {
                                $('#preview-summary').html(esc(labels.summary.replace(':ok', res.ok).replace(':shown', res.rows.length).replace(':total', res.total)));
                            }

                            $.each(res.rows, function (i, row) {
                                html += '<tr class="' + (row.ok ? '' : 'pv-error') + '">' +
                                    '<td>' + esc(row.row) + '</td>' +
                                    '<td>' + esc(row.ok ? row.date : row.cells.slice(0, 1).join('')) + '</td>' +
                                    '<td>' + esc(row.account) + '</td>' +
                                    '<td class="text-right">' + esc(row.amount) + '</td>' +
                                    '<td>' + esc(row.kind) + '</td>' +
                                    '<td>' + (row.ok
                                        ? '<span class="text-success"><i class="fa fa-check-circle mr-1"></i>' + esc(labels.ok) + '</span>'
                                        : '<span class="text-danger">' + esc(row.errors.join(' ')) + '</span>') + '</td>' +
                                    '</tr>';
                            });

                            $('#preview-body').html(html);
                            $('#preview-box').show();
                            $('html, body').animate({scrollTop: $('#preview-box').offset().top - 80}, 200);
                        },
                        error: function (xhr) {
                            var message = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : labels.failed;
                            $('#preview-summary').html('<div class="alert alert-danger mb-2">' + esc(message) + '</div>');
                            $('#preview-box').show();
                        },
                        complete: function () {
                            btn.prop('disabled', false).find('i').attr('class', 'fa fa-eye mr-1');
                        }
                    });
                });

                $('#mapping-form').on('submit', function (e) {
                    var submitter = e.originalEvent && e.originalEvent.submitter;
                    if (submitter && submitter.id === 'import-btn') {
                        setTimeout(function () {
                            $(submitter).prop('disabled', true).find('i').attr('class', 'fa fa-spinner fa-spin mr-1');
                        }, 0);
                    }
                });
            });
        </script>
    @endif
@endpush
