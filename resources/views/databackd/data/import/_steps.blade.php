{{-- Wizard step indicator (.dbk-stepper). Variable: $step (1..3; 4 = all steps done) --}}
@php
    $wizardSteps = [1 => 'upload', 2 => 'mapping', 3 => 'import'];
@endphp

<ol class="dbk-stepper fin-import-steps">
    @foreach ($wizardSteps as $number => $key)
        <li @class(['is-done' => $number < $step, 'is-active' => $number == $step]) @if ($number == $step) aria-current="step" @endif>
            <span>{{ __('databackd-data-import.steps.' . $key) }}</span>
        </li>
    @endforeach
</ol>
