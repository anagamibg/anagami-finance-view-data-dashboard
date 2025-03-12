@extends('layouts.app')

@section('content')
    <div class="content-wrapper">
        @include('databackd.gaps.bank-import._nav', ['active' => 'import'])
        @include('databackd.gaps.bank-import._steps', ['step' => 1])

        <div class="row">
            <div class="col-lg-8 mb-4">
                <x-cards.data :title="__('databackd-gaps-bank-import.upload.title')">
                    <x-form id="bank-import-upload-form">
                        <p class="text-dark-grey f-14">{{ __('databackd-gaps-bank-import.upload.intro') }}</p>

                        @if ($entities->isEmpty())
                            <div class="alert alert-warning">
                                {{ __('databackd-gaps-bank-import.upload.entity_none') }}
                                @if (Route::has('databackd.data.entities.index'))
                                    <a href="{{ route('databackd.data.entities.index') }}" class="alert-link d-inline-block mt-1">{{ __('databackd-gaps-bank-import.upload.entity_add') }}</a>
                                @endif
                            </div>
                        @endif

                        <div class="row">
                            <div class="col-md-12">
                                <x-forms.file :fieldLabel="__('databackd-gaps-bank-import.upload.file_label')"
                                              fieldName="statement_file" fieldId="statement_file" :fieldRequired="true"
                                              allowedFileExtensions="sta mt940 940 swi txt xml"
                                              :fieldHelp="__('databackd-gaps-bank-import.upload.file_help', ['mb' => $maxMb])"/>
                            </div>
                            <div class="col-md-8">
                                <x-forms.select fieldId="entity_id" fieldName="entity_id"
                                                :fieldLabel="__('databackd-gaps-bank-import.upload.entity_label')" :fieldRequired="true">
                                    @foreach ($entities as $entity)
                                        <option value="{{ $entity->id }}" @selected($loop->first)>{{ $entity->name }}</option>
                                    @endforeach
                                </x-forms.select>
                                <small class="form-text text-muted">{{ __('databackd-gaps-bank-import.upload.entity_help') }}</small>
                            </div>
                        </div>

                        <div class="mt-4">
                            <x-forms.button-primary id="bank-import-upload-btn" icon="arrow-right" :disabled="$entities->isEmpty()">
                                {{ __('databackd-gaps-bank-import.upload.next') }}
                            </x-forms.button-primary>
                        </div>
                    </x-form>
                </x-cards.data>

                <x-cards.data :title="__('databackd-gaps-bank-import.how.title')" class="mt-4">
                    <ol class="pl-3 mb-0 text-dark-grey f-14">
                        <li class="mb-2">{{ __('databackd-gaps-bank-import.how.step1') }}</li>
                        <li class="mb-2">{{ __('databackd-gaps-bank-import.how.step2') }}</li>
                        <li class="mb-2">{{ __('databackd-gaps-bank-import.how.step3') }}</li>
                        <li>{{ __('databackd-gaps-bank-import.how.step4') }}</li>
                    </ol>
                </x-cards.data>
            </div>

            <div class="col-lg-4 mb-4">
                <x-cards.data :title="__('databackd-gaps-bank-import.samples.title')">
                    <p class="text-dark-grey f-14">{{ __('databackd-gaps-bank-import.samples.text') }}</p>
                    <div class="d-flex flex-column">
                        <x-forms.link-primary :link="route('databackd.gaps.bank-import.sample', ['format' => 'mt940'])" icon="file-alt" class="mb-2">
                            {{ __('databackd-gaps-bank-import.samples.mt940') }}
                        </x-forms.link-primary>
                        <x-forms.link-secondary :link="route('databackd.gaps.bank-import.sample', ['format' => 'camt053'])" icon="file-code">
                            {{ __('databackd-gaps-bank-import.samples.camt053') }}
                        </x-forms.link-secondary>
                    </div>
                    <p class="text-lightest f-12 mt-3 mb-0">{{ __('databackd-gaps-bank-import.samples.note') }}</p>

                    <h6 class="mt-4 mb-2 f-14 f-w-500">{{ __('databackd-gaps-bank-import.samples.formats_title') }}</h6>
                    <ul class="pl-3 mb-0 f-13 text-dark-grey">
                        <li class="mb-1">{{ __('databackd-gaps-bank-import.samples.format_mt940') }}</li>
                        <li>{{ __('databackd-gaps-bank-import.samples.format_camt') }}</li>
                    </ul>
                </x-cards.data>

                <x-cards.data :title="__('databackd-gaps-bank-import.recent.title')" class="mt-4">
                    @forelse ($recent as $statement)
                        @php
                            $undone = ($statement->importBatch->status ?? null) === 'undone';
                        @endphp
                        <div class="d-flex justify-content-between align-items-start mb-2 f-13 @if ($undone) text-lightest @endif">
                            <div>
                                <div class="f-w-500">{{ $statement->account_iban ?: '—' }}</div>
                                <div class="text-lightest">
                                    {{ $statement->date_from?->format(company()->date_format) }} – {{ $statement->date_to?->format(company()->date_format) }}
                                    · {{ $statement->entity->name ?? '' }}
                                </div>
                            </div>
                            @if ($statement->import_batch_id && !$undone)
                                <a href="{{ route('databackd.gaps.bank-import.result', $statement->import_batch_id) }}" class="f-12">@lang('databackd-gaps-bank-import.recent.open')</a>
                            @elseif ($undone)
                                <span class="badge badge-secondary">@lang('databackd-gaps-bank-import.statements.undone')</span>
                            @endif
                        </div>
                    @empty
                        <p class="text-lightest f-13 mb-0">{{ __('databackd-gaps-bank-import.recent.empty') }}</p>
                    @endforelse

                    <div class="mt-3 f-13">
                        <a href="{{ route('databackd.gaps.bank-import.statements') }}">@lang('databackd-gaps-bank-import.recent.all', ['count' => $statementsCount])</a>
                        <span class="text-lightest mx-1">·</span>
                        <a href="{{ route('databackd.gaps.bank-import.rules.index') }}">@lang('databackd-gaps-bank-import.recent.rules', ['count' => $rulesCount])</a>
                    </div>
                </x-cards.data>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('#statement_file').dropify({messages: dropifyMessages});

            $('#bank-import-upload-btn').on('click', function () {
                $.easyAjax({
                    url: "{{ route('databackd.gaps.bank-import.upload') }}",
                    container: '#bank-import-upload-form',
                    type: 'POST',
                    disableButton: true,
                    blockUI: true,
                    buttonSelector: '#bank-import-upload-btn',
                    file: true,
                    data: $('#bank-import-upload-form').serialize(),
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
