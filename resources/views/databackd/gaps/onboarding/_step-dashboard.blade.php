{{-- Step 5: summary and finish. Variables: $companyModel, $steps, $summary, $onboarding, $dashboardUrl, $prevUrl --}}
@php
    $template = $onboarding->chart_template;
    $rows = [
        'company' => ['label' => __('databackd-gaps-onboarding.dashboard.company'), 'value' => $companyModel->company_name],
        'entities' => ['label' => __('databackd-gaps-onboarding.dashboard.entities'), 'value' => __('databackd-gaps-onboarding.dashboard.entities_value', ['count' => $summary['entities']])],
        'chart' => ['label' => __('databackd-gaps-onboarding.dashboard.chart'), 'value' => __('databackd-gaps-onboarding.dashboard.chart_value', [
            'template' => $template ? __('databackd-gaps-onboarding.templates.' . $template) : __('databackd-gaps-onboarding.dashboard.chart_default'),
            'active' => $summary['accounts_active'],
        ])],
        'import' => ['label' => __('databackd-gaps-onboarding.dashboard.import'), 'value' => __('databackd-gaps-onboarding.dashboard.import_value', ['entries' => number_format($summary['entries'], 0, ',', ' '), 'imports' => $summary['imports']])],
    ];
@endphp

<x-dbk.card :title="__('databackd-gaps-onboarding.steps.dashboard.title')" :subtitle="__('databackd-gaps-onboarding.dashboard.intro')">
    <h6 class="f-14 f-w-500 mb-0">@lang('databackd-gaps-onboarding.dashboard.summary')</h6>
    <div class="mb-3">
        @foreach ($rows as $key => $row)
            @php
                $status = $steps[$key];
                $badge = $status === 'done' ? 'is-good' : ($status === 'skipped' ? 'is-warning' : '');
            @endphp
            <div class="onb-summary-row">
                <div>
                    <div class="onb-summary-label">{{ $row['label'] }}</div>
                    <div class="onb-summary-value">{{ $row['value'] }}</div>
                </div>
                <div class="d-flex align-items-center">
                    <span class="dbk-badge {{ $badge }} mr-3">{{ __('databackd-gaps-onboarding.status.' . $status) }}</span>
                    <a href="{{ route('databackd.gaps.onboarding.index', ['step' => array_search($key, $stepKeys, true) + 1]) }}" class="f-13">{{ __('databackd-gaps-onboarding.steps.' . $key . '.short') }}</a>
                </div>
            </div>
        @endforeach
    </div>

    @if ($summary['entries'] > 0)
        <div class="alert alert-success f-14 mb-0"><i class="fa fa-check-circle mr-1"></i>@lang('databackd-gaps-onboarding.dashboard.ready')</div>
    @else
        <div class="alert alert-warning f-14 mb-0"><i class="fa fa-exclamation-triangle mr-1"></i>@lang('databackd-gaps-onboarding.dashboard.no_data')</div>
    @endif

    @unless ($dashboardUrl)
        <p class="f-13 text-dark-grey mt-3 mb-0">@lang('databackd-gaps-onboarding.dashboard.not_available')</p>
    @endunless

    <div class="onb-actions">
        @if ($prevUrl)
            <x-forms.link-secondary :link="$prevUrl" icon="arrow-left">@lang('databackd-gaps-onboarding.actions.back')</x-forms.link-secondary>
        @endif
        <span class="onb-spacer"></span>
        <x-forms.button-primary id="onb-complete" icon="flag-checkered" data-onb-post="{{ route('databackd.gaps.onboarding.complete') }}">
            {{ $dashboardUrl ? __('databackd-gaps-onboarding.actions.finish_open') : __('databackd-gaps-onboarding.actions.finish') }}
        </x-forms.button-primary>
    </div>
</x-dbk.card>
