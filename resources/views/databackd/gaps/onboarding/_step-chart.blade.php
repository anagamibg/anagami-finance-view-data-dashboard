{{-- Step 3: chart of accounts template. Variables: $templates (template => [code => name]), $onboarding, $summary, $accountsUrl, $prevUrl --}}
@php
    $selected = $onboarding->chart_template ?: ($summary['entries'] > 0 ? 'nss' : 'micro');
@endphp

<x-dbk.card :title="__('databackd-gaps-onboarding.steps.chart.title')" :subtitle="__('databackd-gaps-onboarding.chart.intro')">
    <p class="f-13 text-dark-grey mb-3">
        <i class="fa fa-info-circle mr-1"></i>{{ __('databackd-gaps-onboarding.chart.current', ['active' => $summary['accounts_active'], 'total' => $summary['accounts_total'], 'used' => $summary['accounts_in_use']]) }}
    </p>

    <x-form id="onb-chart-form" class="ajax-form">
        <div class="row">
            @foreach ($templates as $template => $accounts)
                <div class="col-lg-6 mb-3 d-flex flex-column">
                    <label class="onb-choice flex-grow-1 {{ $selected === $template ? 'is-selected' : '' }}" for="onb-tpl-{{ $template }}">
                        <input type="radio" name="template" id="onb-tpl-{{ $template }}" value="{{ $template }}" @checked($selected === $template)>
                        <span>
                            <span class="onb-choice-title">@lang('databackd-gaps-onboarding.chart.' . $template . '.title')</span>
                            <span class="onb-choice-text">@lang('databackd-gaps-onboarding.chart.' . $template . '.text')</span>
                            <span class="dbk-badge is-info no-dot">{{ __('databackd-gaps-onboarding.chart.' . $template . '.count', ['count' => count($accounts)]) }}</span>
                            @if ($onboarding->chart_template === $template)
                                <span class="dbk-badge is-good no-dot ml-1">@lang('databackd-gaps-onboarding.chart.current_badge')</span>
                            @endif
                        </span>
                    </label>
                    <details class="onb-accounts">
                        <summary>@lang('databackd-gaps-onboarding.actions.show_accounts')</summary>
                        <ul>
                            @foreach ($accounts as $code => $name)
                                <li><b>{{ $code }}</b> {{ $name }}</li>
                            @endforeach
                        </ul>
                    </details>
                </div>
            @endforeach
        </div>

        <p class="f-13 text-dark-grey mb-0"><i class="fa fa-shield-alt mr-1"></i>@lang('databackd-gaps-onboarding.chart.safe')</p>

        <div class="onb-actions">
            @if ($prevUrl)
                <x-forms.link-secondary :link="$prevUrl" icon="arrow-left">@lang('databackd-gaps-onboarding.actions.back')</x-forms.link-secondary>
            @endif
            @if ($accountsUrl)
                <a href="{{ $accountsUrl }}" class="f-14"><i class="fa fa-list mr-1"></i>@lang('databackd-gaps-onboarding.chart.open_accounts')</a>
            @endif
            <span class="onb-spacer"></span>
            <x-forms.button-secondary id="onb-chart-skip" icon="forward" data-onb-post="{{ route('databackd.gaps.onboarding.step', 'chart') }}"
                                      data-onb-data='{"status":"skipped"}'>
                @lang('databackd-gaps-onboarding.actions.skip')
            </x-forms.button-secondary>
            <x-forms.button-primary id="onb-chart-save" icon="check" data-onb-form="#onb-chart-form"
                                    data-onb-submit="{{ route('databackd.gaps.onboarding.chart') }}">
                @lang('databackd-gaps-onboarding.actions.apply_continue')
            </x-forms.button-primary>
        </div>
    </x-form>
</x-dbk.card>

@push('scripts')
    <script>
        $(function () {
            $('#onb-chart-form').on('change', 'input[name=template]', function () {
                $('#onb-chart-form .onb-choice').removeClass('is-selected');
                $(this).closest('.onb-choice').addClass('is-selected');
            });
        });
    </script>
@endpush
