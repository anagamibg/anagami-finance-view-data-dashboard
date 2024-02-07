@extends('layouts.app')

@section('content')
    <div class="content-wrapper dbk-data-page dbk-data-import">
        @include('databackd.data.import._steps', ['step' => 2])

        @if (empty($meta))
            <div class="dbk-card">
                <x-dbk.empty icon="cloud-arrow-up" :title="__('databackd-data-import.mapping.no_file_title')" :text="__('databackd-data-import.mapping.no_file_text')">
                    <x-forms.link-primary :link="route('databackd.data.import.index')" icon="arrow-left">
                        {{ __('databackd-data-import.mapping.no_file_button') }}
                    </x-forms.link-primary>
                </x-dbk.empty>
            </div>
        @else
            <x-form id="fin-import-run-form">
                <input type="hidden" name="token" value="{{ $token }}">

                <x-dbk.card :title="__('databackd-data-import.mapping.title')">
                    <div class="dbk-data-file-info">
                        <span class="dbk-data-file-icon" aria-hidden="true"><i class="fa fa-file-alt"></i></span>
                        <div>
                            <p class="mb-0">{{ __('databackd-data-import.mapping.file_info', ['name' => $meta['name'], 'rows' => $totalRows, 'columns' => count($headers)]) }}</p>
                            @if (!empty($rememberedBatch))
                                {{-- AFV (S12): the mapping of the previous import of a file with the same columns --}}
                                <p class="mb-0 f-13 dbk-text-good">
                                    <i class="fa fa-history mr-1" aria-hidden="true"></i>{{ __('databackd-data-import.mapping.remembered', ['id' => $rememberedBatch->id, 'date' => $rememberedBatch->created_at?->timezone(company()->timezone)->format('d.m.Y')]) }}
                                </p>
                            @else
                                <p class="mb-0 f-13 {{ $mapping ? 'dbk-text-good' : 'dbk-text-warning' }}">
                                    <i class="fa fa-{{ $mapping ? 'magic' : 'exclamation-triangle' }} mr-1" aria-hidden="true"></i>{{ $mapping ? __('databackd-data-import.mapping.guessed') : __('databackd-data-import.mapping.guessed_none') }}
                                </p>
                            @endif
                        </div>
                    </div>

                    @if (!empty($sameFileBatch))
                        {{-- AFV (S12): the same file (byte for byte) was imported before --}}
                        <div class="alert alert-warning f-14 fin-import-duplicate" role="alert">
                            <i class="fa fa-exclamation-triangle mr-1" aria-hidden="true"></i>
                            {{ __('databackd-data-import.mapping.same_file', ['date' => $sameFileBatch->created_at?->timezone(company()->timezone)->format('d.m.Y H:i'), 'id' => $sameFileBatch->id, 'rows' => $sameFileBatch->rows_imported]) }}
                            @if (Route::has('databackd.data.batches.show'))
                                <a href="{{ route('databackd.data.batches.show', $sameFileBatch->id) }}" class="f-w-500 ml-1">{{ __('databackd-data-import.mapping.same_file_link', ['id' => $sameFileBatch->id]) }}</a>
                            @endif
                        </div>
                    @endif

                    <div class="row mb-3">
                        <div class="col-md-5">
                            <label class="f-14 text-dark-grey mb-1" for="entity_id">{{ __('databackd-data-import.mapping.entity_label') }}</label>
                            <select name="entity_id" id="entity_id" class="form-control form-control-sm">
                                <option value="">{{ __('databackd-data-import.mapping.entity_placeholder') }}</option>
                                @foreach ($entities as $entity)
                                    <option value="{{ $entity->id }}" @selected($entityId == $entity->id)>{{ $entity->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-7">
                            <div class="form-check mt-md-4 pt-md-1">
                                <input class="form-check-input" type="checkbox" name="create_missing_dimensions" id="create_missing_dimensions" value="1" checked>
                                <label class="form-check-label f-14 text-dark-grey" for="create_missing_dimensions">
                                    {{ __('databackd-data-import.mapping.create_missing') }}
                                </label>
                                <small class="form-text text-muted">{{ __('databackd-data-import.mapping.create_missing_help') }}</small>
                            </div>
                        </div>
                    </div>

                    {{-- AFV (S8): platform template that was used for this file --}}
                    @if (!empty($templateInfo))
                        <div class="alert alert-info f-13 mb-3">
                            <div class="d-flex flex-wrap align-items-center mb-1">
                                <span class="f-w-500 mr-2">{{ __('databackd-gaps-import-templates.mapping.title', ['name' => $templateInfo['name']]) }}</span>
                                <span class="badge badge-info">{{ __('databackd-gaps-import-templates.mapping.badge') }}</span>
                            </div>
                            <p class="mb-1">{{ $templateInfo['computed'] ? __('databackd-gaps-import-templates.mapping.computed') : __('databackd-gaps-import-templates.mapping.direct') }}</p>
                            <p class="mb-1">{{ $templateInfo['layout_note'] }}</p>
                            @if ($templateInfo['computed'])
                                <p class="mb-1 f-w-500">{{ __('databackd-gaps-import-templates.mapping.rows', ['out' => $templateInfo['rows_out'], 'in' => $templateInfo['rows_in']]) }}</p>
                            @endif
                            @if (!empty($templateInfo['skipped']))
                                <p class="mb-1">
                                    {{ __('databackd-gaps-import-templates.mapping.skipped') }}:
                                    @foreach ($templateInfo['skipped'] as $skipped)
                                        <span class="badge badge-light border">{{ $skipped['label'] }}: {{ $skipped['count'] }}</span>
                                    @endforeach
                                </p>
                            @endif
                            @if (!empty($templateInfo['found']))
                                <p class="mb-1">
                                    {{ __('databackd-gaps-import-templates.mapping.found') }}:
                                    @foreach ($templateInfo['found'] as $found)
                                        <span class="badge badge-light border">{{ $found['role'] }} &larr; {{ $found['column'] }}</span>
                                    @endforeach
                                </p>
                            @endif
                            @if (!empty($templateInfo['missing']))
                                <p class="mb-1 text-danger">{{ __('databackd-gaps-import-templates.mapping.missing', ['columns' => implode(', ', $templateInfo['missing'])]) }}</p>
                            @endif
                            <p class="mb-0 text-dark-grey">
                                <i class="fa fa-info-circle mr-1"></i>{{ __('databackd-gaps-import-templates.select.warning') }}
                                @if (!empty($templateInfo['sample_url']))
                                    <a href="{{ $templateInfo['sample_url'] }}" download class="f-w-500 ml-1"><i class="fa fa-download mr-1"></i>{{ __('databackd-gaps-import-templates.select.download_sample') }}</a>
                                @endif
                            </p>
                        </div>
                    @endif

                    @include('databackd.data.import._mapping', [
                        'headers' => $headers,
                        'rows' => $sampleRows,
                        'fields' => $fields,
                        'mapping' => $mapping,
                        'virtualColumns' => $virtualColumns ?? [], // AFV (S8)
                        'previewUrl' => route('databackd.data.import.preview'),
                    ])
                </x-dbk.card>

                <div class="dbk-data-form-actions dbk-data-sticky-actions">
                    <x-forms.button-primary id="fin-import-run-btn" icon="check">
                        {{ __('databackd-data-import.mapping.import_button', ['count' => $totalRows]) }}
                    </x-forms.button-primary>
                    <x-forms.link-secondary :link="route('databackd.data.import.index')" icon="arrow-left">
                        {{ __('databackd-data-import.mapping.back') }}
                    </x-forms.link-secondary>
                </div>
            </x-form>
        @endif
    </div>
@endsection

@push('scripts')
    @if (!empty($meta))
        <script>
            $(function () {
                $('#fin-import-run-btn').on('click', function () {
                    $.easyAjax({
                        url: "{{ route('databackd.data.import.run') }}",
                        container: '#fin-import-run-form',
                        type: 'POST',
                        disableButton: true,
                        blockUI: true,
                        buttonSelector: '#fin-import-run-btn',
                        data: $('#fin-import-run-form').serialize(),
                        success: function (response) {
                            if (response.status === 'success') {
                                window.location.href = response.redirect_url;
                            }
                        }
                    });
                });
            });
        </script>
    @endif
@endpush
