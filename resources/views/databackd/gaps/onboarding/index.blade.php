@extends('layouts.app')

@push('styles')
    <style>
        .dbk-onb .onb-progress-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 4px 16px; margin-bottom: 8px; }
        .dbk-onb .onb-progress-step { font-size: 16px; font-weight: 600; color: var(--dbk-text, #1d2633); }
        .dbk-onb .onb-progress-percent { font-size: 13px; color: var(--dbk-text-muted, #66717f); }
        .dbk-onb .dbk-progress { height: 8px; }
        .dbk-onb .dbk-stepper { margin: 20px 0 0; padding-bottom: 4px; }
        .dbk-onb .dbk-stepper > li > a { color: inherit; text-decoration: none; display: flex; flex-direction: column; line-height: 1.25; }
        .dbk-onb .dbk-stepper > li > a:hover .onb-step-name { text-decoration: underline; }
        .dbk-onb .dbk-stepper .onb-step-status { font-size: 12px; font-weight: 400; color: var(--dbk-text-muted, #66717f); }
        .dbk-onb .dbk-stepper > li.is-skipped::before { background: var(--dbk-text-muted, #66717f); border-color: var(--dbk-text-muted, #66717f); }
        .dbk-onb .btn { text-transform: none; }
        .dbk-onb .onb-lead { color: var(--dbk-text-2, #4f5b6b); font-size: 14px; margin-bottom: 8px; }
        .dbk-onb .onb-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--dbk-border, #e4e9f0); }
        .dbk-onb .onb-actions .onb-spacer { flex: 1 1 auto; }
        .dbk-onb .onb-choice { display: flex; gap: 12px; align-items: flex-start; margin: 0; padding: 16px; cursor: pointer; border: 1px solid var(--dbk-border, #e4e9f0); border-radius: var(--dbk-radius, 10px); background: var(--dbk-surface, #fff); transition: border-color .15s, background-color .15s; }
        .dbk-onb .onb-choice:hover { border-color: var(--dbk-border-strong, #cfd7e2); }
        .dbk-onb .onb-choice.is-selected { border-color: var(--dbk-accent, #1d6fd6); background: var(--dbk-accent-soft, #e8f1fc); box-shadow: 0 0 0 1px var(--dbk-accent, #1d6fd6); }
        .dbk-onb .onb-choice input[type=radio] { margin-top: 4px; flex-shrink: 0; }
        .dbk-onb .onb-choice-title { display: block; font-size: 15px; font-weight: 600; color: var(--dbk-text, #1d2633); margin-bottom: 4px; }
        .dbk-onb .onb-choice-text { display: block; font-size: 13px; line-height: 19px; color: var(--dbk-text-2, #4f5b6b); margin-bottom: 8px; }
        .dbk-onb .onb-accounts { margin-top: 8px; font-size: 13px; }
        .dbk-onb .onb-accounts summary { cursor: pointer; color: var(--dbk-accent, #1d6fd6); }
        .dbk-onb .onb-accounts ul { list-style: none; margin: 8px 0 0; padding: 0; columns: 2; column-gap: 24px; }
        .dbk-onb .onb-accounts li { break-inside: avoid; padding: 2px 0; color: var(--dbk-text-2, #4f5b6b); }
        .dbk-onb .onb-accounts li b { display: inline-block; min-width: 34px; color: var(--dbk-text, #1d2633); font-variant-numeric: tabular-nums; }
        .dbk-onb .onb-sub-row { border: 1px solid var(--dbk-border, #e4e9f0); border-radius: var(--dbk-radius-sm, 6px); padding: 4px 12px 0; margin-bottom: 12px; background: var(--dbk-surface-2, #f7f9fb); }
        .dbk-onb .onb-sub-row .form-group { margin: 8px 0; }
        .dbk-onb .onb-sub-row label { font-size: 12px; margin-bottom: 2px; color: var(--dbk-text-muted, #66717f); }
        .dbk-onb .onb-summary-row { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 4px 16px; padding: 12px 0; border-bottom: 1px solid var(--dbk-border, #e4e9f0); }
        .dbk-onb .onb-summary-row:last-child { border-bottom: 0; }
        .dbk-onb .onb-summary-label { font-weight: 600; color: var(--dbk-text, #1d2633); }
        .dbk-onb .onb-summary-value { color: var(--dbk-text-2, #4f5b6b); font-size: 14px; }
        @media (max-width: 575.98px) {
            /* phone: all five steps fit on one line - only the current step shows its name, the others are circles (names stay for screen readers) */
            .dbk-onb .dbk-stepper { overflow: visible; }
            .dbk-onb .dbk-stepper > li { position: relative; min-height: 40px; }
            .dbk-onb .dbk-stepper > li:not(.is-active) { min-width: 0; flex: 1 1 auto; }
            .dbk-onb .dbk-stepper > li.is-active { min-width: 0; flex: 0 1 auto; }
            .dbk-onb .dbk-stepper > li:not(.is-active) > a { position: absolute; inset: 0; }
            .dbk-onb .dbk-stepper > li:not(.is-active) > a > span { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
            .dbk-onb .onb-accounts ul { columns: 1; }
            .dbk-onb .onb-actions > * { flex: 1 1 100%; }
            .dbk-onb .onb-actions .onb-spacer { display: none; }
        }
    </style>
@endpush

@section('content')
    @php
        $total = $progress['total'];
    @endphp

    <div class="content-wrapper dbk-onb">
        <x-dbk.page-header :title="__('databackd-gaps-onboarding.title')" :subtitle="__('databackd-gaps-onboarding.subtitle')">
            <x-slot name="actions">
                @unless ($onboarding->completed_at)
                    @if ($onboarding->dismissed_at)
                        <x-forms.button-secondary icon="eye" data-onb-post="{{ route('databackd.gaps.onboarding.restore') }}">
                            @lang('databackd-gaps-onboarding.actions.restore')
                        </x-forms.button-secondary>
                    @else
                        <x-forms.button-secondary icon="eye-slash" data-onb-post="{{ route('databackd.gaps.onboarding.dismiss') }}" data-onb-reload="1">
                            @lang('databackd-gaps-onboarding.actions.dismiss')
                        </x-forms.button-secondary>
                    @endif
                @endunless
            </x-slot>
        </x-dbk.page-header>

        @if ($onboarding->completed_at)
            <div class="alert alert-success f-14">
                <i class="fa fa-check-circle mr-1"></i>{{ __('databackd-gaps-onboarding.state.completed', ['date' => $onboarding->completed_at->format('d.m.Y')]) }}
            </div>
        @elseif ($onboarding->dismissed_at)
            <div class="alert alert-info f-14">
                <i class="fa fa-info-circle mr-1"></i>@lang('databackd-gaps-onboarding.state.dismissed')
            </div>
        @endif

        {{-- progress + step indicator --}}
        <x-dbk.card class="mb-3">
            <div class="onb-progress-head">
                <span class="onb-progress-step">{{ __('databackd-gaps-onboarding.step_of', ['current' => $stepNo, 'total' => $total]) }}</span>
                <span class="onb-progress-percent">
                    {{ __('databackd-gaps-onboarding.steps_handled', ['done' => $progress['done'], 'total' => $total]) }}
                    · {{ __('databackd-gaps-onboarding.percent_done', ['percent' => $progress['percent']]) }}
                </span>
            </div>
            <div class="dbk-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}"
                 aria-label="{{ __('databackd-gaps-onboarding.percent_done', ['percent' => $progress['percent']]) }}">
                <span class="is-good" style="width: {{ $progress['percent'] }}%"></span>
            </div>

            <ol class="dbk-stepper">
                @foreach ($stepKeys as $index => $key)
                    @php
                        $status = $steps[$key];
                        $classes = [
                            'is-active' => $key === $stepKey,
                            'is-done' => $status !== 'pending',
                            'is-skipped' => $status === 'skipped',
                        ];
                    @endphp
                    <li @class($classes) @if ($key === $stepKey) aria-current="step" @endif>
                        <a href="{{ route('databackd.gaps.onboarding.index', ['step' => $index + 1]) }}">
                            <span class="onb-step-name">{{ __('databackd-gaps-onboarding.steps.' . $key . '.short') }}</span>
                            <span class="onb-step-status">{{ __('databackd-gaps-onboarding.status.' . $status) }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </x-dbk.card>

        {{-- the current step --}}
        @include('databackd.gaps.onboarding._step-' . $stepKey)
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            // safety net: a native submit (Enter in a single-field form) would POST to the GET-only wizard URL (405) - send it through AJAX instead
            $('.dbk-onb form.ajax-form').on('submit', function (e) {
                e.preventDefault();
                $(this).find('[data-onb-submit]').first().trigger('click');
            });

            // submit a step form through AJAX (the controller answers with a redirect to the next step)
            $('[data-onb-submit]').on('click', function () {
                var form = $(this).data('onb-form');

                $.easyAjax({
                    url: $(this).data('onb-submit'),
                    container: form,
                    type: 'POST',
                    blockUI: true,
                    disableButton: true,
                    buttonSelector: '#' + this.id,
                    data: $(form).serialize()
                });
            });

            // plain POST actions: skip / mark done / complete / hide / show again
            $('[data-onb-post]').on('click', function () {
                var $button = $(this), reload = $button.data('onb-reload');

                $.easyAjax({
                    url: $button.data('onb-post'),
                    container: 'body',
                    type: 'POST',
                    blockUI: true,
                    data: Object.assign({_token: '{{ csrf_token() }}'}, $button.data('onb-data') || {}),
                    success: function (response) {
                        if (response.status === 'success' && reload) {
                            window.location.reload();
                        }
                    }
                });
            });
        });
    </script>
@endpush
