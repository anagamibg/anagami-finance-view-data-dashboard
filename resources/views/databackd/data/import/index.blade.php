@extends('layouts.app')

@section('content')
    <div class="content-wrapper dbk-data-page dbk-data-import">
        @include('databackd.data.import._steps', ['step' => 1])

        <div class="dbk-grid">
            <div class="dbk-span-8 dbk-data-stack">
                <x-dbk.card :title="__('databackd-data-import.upload.title')" :subtitle="__('databackd-data-import.upload.intro')">
                    <x-form id="fin-import-upload-form">

                        @if ($entities->isEmpty())
                            <div class="alert alert-warning">{{ __('databackd-data-import.upload.entity_none') }}</div>
                        @endif

                        <div class="row">
                            {{-- AFV (S8): platform template (Microinvest, Ajur, Plus-Minus, Business Navigator) --}}
                            <div class="col-md-12">
                                <x-forms.select fieldId="import_template" fieldName="template"
                                                :fieldLabel="__('databackd-gaps-import-templates.select.label')">
                                    <option value="" data-layout="">{{ __('databackd-gaps-import-templates.select.auto') }}</option>
                                    @foreach ($platforms as $platform)
                                        <optgroup label="{{ $platform['name'] }}">
                                            @foreach ($platform['templates'] as $template)
                                                <option value="{{ $template['id'] }}" data-layout="{{ $template['layout'] }}"
                                                        @selected($selectedTemplate === $template['id'])>{{ $template['name'] }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </x-forms.select>
                                <small class="form-text text-muted">{{ __('databackd-gaps-import-templates.select.help') }}</small>

                                @foreach ($platforms as $platform)
                                    @foreach ($platform['templates'] as $template)
                                        <div class="fin-template-info alert alert-light border mt-3 mb-0 f-13" data-template="{{ $template['id'] }}" style="display: none;">
                                            <div class="f-w-500 mb-1">{{ $template['name'] }}</div>
                                            <p class="mb-1">{{ $template['description'] }}</p>
                                            <p class="mb-1 text-dark-grey">{{ __('databackd-gaps-import-templates.layouts.' . $template['layout'] . '.how') }}</p>
                                            <p class="mb-1 text-dark-grey">
                                                {{ __('databackd-gaps-import-templates.select.format') }}
                                                {{ __('databackd-gaps-import-templates.select.typical', ['delimiter' => $template['delimiter'], 'decimal' => $template['decimal'], 'encoding' => $template['encoding']]) }}
                                            </p>
                                            <p class="mb-2 text-warning"><i class="fa fa-info-circle mr-1"></i>{{ __('databackd-gaps-import-templates.select.warning') }}</p>
                                            @if ($template['sample_url'])
                                                <a href="{{ $template['sample_url'] }}" download class="f-w-500">
                                                    <i class="fa fa-download mr-1"></i>{{ __('databackd-gaps-import-templates.select.download_sample') }}
                                                </a>
                                            @endif
                                        </div>
                                    @endforeach
                                @endforeach
                            </div>
                            <div class="col-md-6" id="fin-template-date" style="display: none;">
                                <x-forms.datepicker fieldId="period_date" fieldName="period_date"
                                                    :fieldLabel="__('databackd-gaps-import-templates.select.date_label')"
                                                    :fieldPlaceholder="__('databackd-gaps-import-templates.select.date_placeholder')"
                                                    :fieldValue="$defaultPeriodDate"
                                                    :fieldHelp="__('databackd-gaps-import-templates.select.date_help')"/>
                                <x-forms.checkbox fieldId="after_closing" fieldName="after_closing" fieldValue="1"
                                                  :fieldLabel="__('databackd-gaps-import-templates.select.after_closing')"/>
                                <small class="form-text text-muted mb-3">{{ __('databackd-gaps-import-templates.select.after_closing_help') }}</small>
                            </div>
                            <div class="col-md-12 dbk-data-dropzone">
                                <x-forms.file :fieldLabel="__('databackd-data-import.upload.file_label')"
                                              fieldName="import_file" fieldId="import_file" :fieldRequired="true"
                                              allowedFileExtensions="xlsx xls csv txt"
                                              :fieldHelp="__('databackd-data-import.upload.file_help', ['size' => $maxUploadMb])"/>
                            </div>
                            <div class="col-md-8">
                                <x-forms.select fieldId="entity_id" fieldName="entity_id"
                                                :fieldLabel="__('databackd-data-import.upload.entity_label')">
                                    @foreach ($entities as $entity)
                                        <option value="{{ $entity->id }}" @selected($loop->first)>{{ $entity->name }}</option>
                                    @endforeach
                                </x-forms.select>
                                <small class="form-text text-muted">{{ __('databackd-data-import.upload.entity_help') }}</small>
                            </div>
                        </div>

                        <div class="dbk-data-form-actions">
                            <x-forms.button-primary id="fin-import-upload-btn" icon="arrow-right">
                                {{ __('databackd-data-import.upload.next') }}
                            </x-forms.button-primary>
                            @if (Route::has('databackd.data.batches.index'))
                                <x-forms.link-secondary :link="route('databackd.data.batches.index')" icon="history">
                                    {{ __('databackd-data-import.how.history') }}
                                </x-forms.link-secondary>
                            @endif
                        </div>
                    </x-form>
                </x-dbk.card>

                <x-dbk.card :title="__('databackd-data-import.how.title')">
                    <ol class="dbk-data-howto">
                        <li class="mb-2">{{ __('databackd-data-import.how.step1') }}</li>
                        <li class="mb-2">{{ __('databackd-data-import.how.step2') }}</li>
                        <li>{{ __('databackd-data-import.how.step3') }}</li>
                    </ol>
                </x-dbk.card>
            </div>

            <div class="dbk-span-4">
                <x-dbk.card :title="__('databackd-data-import.templates.title')" :subtitle="__('databackd-data-import.templates.text')">
                    <div class="dbk-data-template-links">
                        <x-forms.link-primary :link="route('databackd.data.import.template', ['format' => 'xlsx'])" icon="file-excel">
                            {{ __('databackd-data-import.templates.xlsx') }}
                        </x-forms.link-primary>
                        <x-forms.link-secondary :link="route('databackd.data.import.template', ['format' => 'csv'])" icon="file-csv">
                            {{ __('databackd-data-import.templates.csv') }}
                        </x-forms.link-secondary>
                    </div>

                    <h4 class="dbk-data-subhead">{{ __('databackd-data-import.templates.columns_title') }}</h4>
                    <ul class="dbk-data-field-list">
                        @foreach ($fields as $field)
                            <li>
                                <span class="dbk-data-field-name">{{ $field['name'] }}</span>
                                @if ($field['required'])
                                    <span class="dbk-badge is-bad no-dot">{{ __('databackd-data-import.templates.required') }}</span>
                                @endif
                                <span class="dbk-data-field-hint">{{ $field['hint'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-dbk.card>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            // AFV (S12): size and type are checked in the browser too - a file above the PHP limit would otherwise fail with a generic error
            $('#import_file').attr('data-max-file-size', '{{ max(1, (int)floor($maxUploadKb / 1024)) }}M').dropify({
                messages: dropifyMessages,
                error: {
                    fileSize: @json(__('databackd-data-import.upload.too_big')),
                    fileExtension: @json(__('databackd-data-import.upload.wrong_type'))
                }
            });

            // AFV (S12): visible state while a file is dragged over the zone
            $('.dbk-data-dropzone').on('dragenter dragover', '.dropify-wrapper', function () {
                $(this).addClass('is-dragover');
            }).on('dragleave dragend drop', '.dropify-wrapper', function () {
                $(this).removeClass('is-dragover');
            });

            $('#fin-import-upload-btn').on('click', function () {
                $.easyAjax({
                    url: "{{ route('databackd.data.import.upload') }}",
                    container: '#fin-import-upload-form',
                    type: 'POST',
                    disableButton: true,
                    blockUI: true,
                    buttonSelector: '#fin-import-upload-btn',
                    file: true,
                    data: $('#fin-import-upload-form').serialize(),
                    success: function (response) {
                        if (response.status === 'success') {
                            window.location.href = response.redirect_url;
                        }
                    }
                });
            });
        });
    </script>
@endpush

@push('scripts')
    {{-- AFV (S8): platform template – description, example download and the date of a trial balance --}}
    <script>
        $(function () {
            var $template = $('#import_template');

            datepicker('#period_date', {
                position: 'bl',
                ...datepickerConfig
            });

            function syncTemplate() {
                var id = $template.val() || '';
                var layout = $template.find('option:selected').data('layout') || '';

                $('.fin-template-info').hide().filter('[data-template="' + id + '"]').show();
                $('#fin-template-date').toggle(layout === 'trial_balance');
            }

            $template.on('change', syncTemplate);
            syncTemplate();
        });
    </script>
@endpush
