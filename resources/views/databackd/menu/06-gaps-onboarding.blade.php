@if (databackd_module('databackd_data') && in_array('admin', user_roles())) {{-- AFV (S8): package module; setup wizard is for the company admin --}}
    @php
        $dbkOnboardingCompanyId = company()->id ?? null;
        $dbkOnboarding = $dbkOnboardingCompanyId
            ? app(\App\Services\Databackd\Gaps\Onboarding\OnboardingService::class)->visibleState($dbkOnboardingCompanyId)
            : null;
    @endphp
    @if ($dbkOnboarding)
        {{-- AFV ui (S12): direct top-level link, the progress is a badge so the label stays on one line --}}
        <x-menu-item icon="list-check" :text="__('databackd-gaps-onboarding.menu')" :link="route('databackd.gaps.onboarding.index')"
                     :count="$dbkOnboarding['progress']['done'] . '/' . $dbkOnboarding['progress']['total']">
            <x-slot name="iconPath">
                <path fill-rule="evenodd" d="M5 11.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5zM3.854 2.146a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 3.293l1.146-1.147a.5.5 0 0 1 .708 0zm0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 7.293l1.146-1.147a.5.5 0 0 1 .708 0zm0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 0 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0z"/>
            </x-slot>
        </x-menu-item>
    @endif
@endif
