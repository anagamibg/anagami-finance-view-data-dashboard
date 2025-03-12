{{-- Wizard step indicator. Variable: $step (1..3, 4 = all done). Styles: resources/scss/databackd/_bank-fx.scss --}}
@php
    $wizardSteps = [1 => 'upload', 2 => 'review', 3 => 'result'];
@endphp

<ol class="bank-import-steps" aria-label="{{ __('databackd-gaps-bank-import.steps.label') }}">
    @foreach ($wizardSteps as $number => $key)
        <li class="bank-step {{ $number < $step ? 'done' : ($number == $step ? 'active' : '') }}" @if ($number == $step) aria-current="step" @endif>
            <span class="bank-step-no" aria-hidden="true">@if ($number < $step)<i class="fa fa-check f-12"></i>@else{{ $number }}@endif</span>
            @lang('databackd-gaps-bank-import.steps.' . $key)
        </li>
    @endforeach
</ol>
