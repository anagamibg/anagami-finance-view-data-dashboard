{{-- Step 4: first import. Variables: $summary, $importUrl, $templateXlsxUrl, $templateCsvUrl, $sheetsUrl, $sheetsInPackage, $prevUrl, $nextUrl --}}
<x-dbk.card :title="__('databackd-gaps-onboarding.steps.import.title')" :subtitle="__('databackd-gaps-onboarding.import.intro')">
    @if ($summary['entries'] > 0)
        <div class="alert alert-success f-14 mb-3">
            <i class="fa fa-check-circle mr-1"></i>{{ __('databackd-gaps-onboarding.import.detected', ['entries' => number_format($summary['entries'], 0, ',', ' '), 'imports' => $summary['imports']]) }}
        </div>
    @endif

    <div class="row">
        <div class="col-lg-4 mb-3">
            <div class="onb-choice h-100" style="cursor: default">
                <span>
                    <span class="onb-choice-title"><i class="fa fa-file-excel mr-1"></i>@lang('databackd-gaps-onboarding.import.excel_title')</span>
                    <span class="onb-choice-text">@lang('databackd-gaps-onboarding.import.excel_text')</span>
                    @if ($importUrl)
                        <x-forms.link-primary :link="$importUrl" icon="upload" class="mb-2">@lang('databackd-gaps-onboarding.import.open_import')</x-forms.link-primary>
                    @else
                        <span class="dbk-badge no-dot">@lang('databackd-gaps-onboarding.import.unavailable')</span>
                    @endif
                    <span class="d-block mt-1">
                        @if ($templateXlsxUrl)
                            <a href="{{ $templateXlsxUrl }}" class="f-13 d-block"><i class="fa fa-download mr-1"></i>@lang('databackd-gaps-onboarding.import.download_xlsx')</a>
                        @endif
                        @if ($templateCsvUrl)
                            <a href="{{ $templateCsvUrl }}" class="f-13 d-block"><i class="fa fa-download mr-1"></i>@lang('databackd-gaps-onboarding.import.download_csv')</a>
                        @endif
                    </span>
                </span>
            </div>
        </div>

        <div class="col-lg-4 mb-3">
            <div class="onb-choice h-100" style="cursor: default">
                <span>
                    <span class="onb-choice-title"><i class="fa fa-table mr-1"></i>@lang('databackd-gaps-onboarding.import.sheets_title')</span>
                    <span class="onb-choice-text">@lang('databackd-gaps-onboarding.import.sheets_text')</span>
                    @if ($sheetsUrl)
                        <x-forms.link-primary :link="$sheetsUrl" icon="link">@lang('databackd-gaps-onboarding.import.open_sheets')</x-forms.link-primary>
                    @elseif (!$sheetsInPackage)
                        <span class="dbk-badge is-warning no-dot">@lang('databackd-gaps-onboarding.import.sheets_not_in_package')</span>
                    @else
                        <span class="dbk-badge no-dot">@lang('databackd-gaps-onboarding.import.unavailable')</span>
                    @endif
                </span>
            </div>
        </div>

        <div class="col-lg-4 mb-3">
            <div class="onb-choice h-100" style="cursor: default">
                <span>
                    <span class="onb-choice-title"><i class="fa fa-clock mr-1"></i>@lang('databackd-gaps-onboarding.import.later_title')</span>
                    <span class="onb-choice-text">@lang('databackd-gaps-onboarding.import.later_text')</span>
                    <x-forms.button-secondary id="onb-import-later" icon="forward" data-onb-post="{{ route('databackd.gaps.onboarding.step', 'import') }}"
                                              data-onb-data='{"status":"skipped"}'>
                        @lang('databackd-gaps-onboarding.import.skip_later')
                    </x-forms.button-secondary>
                </span>
            </div>
        </div>
    </div>

    <div class="onb-actions">
        @if ($prevUrl)
            <x-forms.link-secondary :link="$prevUrl" icon="arrow-left">@lang('databackd-gaps-onboarding.actions.back')</x-forms.link-secondary>
        @endif
        <span class="onb-spacer"></span>
        @if ($summary['entries'] > 0)
            <x-forms.button-primary id="onb-import-done" icon="check" data-onb-post="{{ route('databackd.gaps.onboarding.step', 'import') }}"
                                    data-onb-data='{"status":"done"}'>
                @lang('databackd-gaps-onboarding.actions.continue')
            </x-forms.button-primary>
        @elseif ($nextUrl)
            <x-forms.link-secondary :link="$nextUrl" icon="arrow-right">@lang('databackd-gaps-onboarding.actions.continue')</x-forms.link-secondary>
        @endif
    </div>
</x-dbk.card>
