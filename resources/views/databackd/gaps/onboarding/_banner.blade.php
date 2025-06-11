{{--
    Setup-wizard banner for the top of the financial dashboard (B5):
        @include('databackd.gaps.onboarding._banner')
    Decides by itself whether to show: company admin only, package module "databackd_data" only, and only while the
    wizard is not completed and not hidden (OnboardingService::visibleState never creates the row and never throws).
--}}
@if (databackd_module('databackd_data') && in_array('admin', user_roles()) && \Illuminate\Support\Facades\Route::has('databackd.gaps.onboarding.index'))
    @php
        $dbkOnbCompanyId = company()->id ?? null;
        $dbkOnb = $dbkOnbCompanyId
            ? app(\App\Services\Databackd\Gaps\Onboarding\OnboardingService::class)->visibleState($dbkOnbCompanyId)
            : null;
    @endphp

    @if ($dbkOnb)
        @php
            $dbkOnbProgress = $dbkOnb['progress'];
        @endphp
        <div class="dbk-card mb-3" id="onb-banner" role="region" aria-label="{{ __('databackd-gaps-onboarding.banner.title') }}">
            <div class="dbk-card-body d-flex flex-wrap align-items-center" style="gap: 12px 24px">
                <div style="flex: 1 1 320px; min-width: 0">
                    <h3 class="dbk-card-title">
                        <i class="fa fa-flag-checkered mr-1" aria-hidden="true"></i>@lang('databackd-gaps-onboarding.banner.title')
                    </h3>
                    <p class="dbk-card-subtitle" style="font-size: 13px; line-height: 18px">
                        {{ __('databackd-gaps-onboarding.banner.text', [
                            'done' => $dbkOnbProgress['done'],
                            'total' => $dbkOnbProgress['total'],
                            'next' => __('databackd-gaps-onboarding.steps.' . $dbkOnbProgress['current'] . '.title'),
                        ]) }}
                    </p>
                    <div class="d-flex align-items-center mt-2" style="gap: 12px; max-width: 460px">
                        <div class="dbk-progress flex-grow-1" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                             aria-valuenow="{{ $dbkOnbProgress['percent'] }}" style="height: 8px">
                            <span class="is-good" style="width: {{ $dbkOnbProgress['percent'] }}%"></span>
                        </div>
                        <span class="f-13 text-dark-grey" style="white-space: nowrap">{{ $dbkOnbProgress['done'] }}/{{ $dbkOnbProgress['total'] }}</span>
                    </div>
                </div>
                <div class="d-flex flex-wrap" style="gap: 8px">
                    <x-forms.link-primary :link="route('databackd.gaps.onboarding.index')" icon="arrow-right">
                        @lang('databackd-gaps-onboarding.banner.continue')
                    </x-forms.link-primary>
                    <x-forms.button-secondary id="onb-banner-dismiss" icon="eye-slash">
                        @lang('databackd-gaps-onboarding.banner.hide')
                    </x-forms.button-secondary>
                </div>
            </div>
        </div>

        @push('styles')
            <style>
                #onb-banner .btn { text-transform: none; }
            </style>
        @endpush

        @push('scripts')
            <script>
                $(function () {
                    $('#onb-banner-dismiss').on('click', function () {
                        $.easyAjax({
                            url: "{{ route('databackd.gaps.onboarding.dismiss') }}",
                            container: '#onb-banner',
                            type: 'POST',
                            blockUI: true,
                            data: {_token: '{{ csrf_token() }}'},
                            success: function (response) {
                                if (response.status === 'success') {
                                    $('#onb-banner').slideUp(150, function () {
                                        $(this).remove();
                                    });
                                }
                            }
                        });
                    });
                });
            </script>
        @endpush
    @endif
@endif
