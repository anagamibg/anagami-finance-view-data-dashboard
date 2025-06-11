{{-- Step 1: company data. Variables: $companyModel, $address, $settingsUrl, $nextUrl --}}
@php
    $currency = $companyModel->currency;
    $currencyText = $currency ? trim($currency->currency_name . ' (' . $currency->currency_code . ')') : 'EUR';
@endphp

<x-dbk.card :title="__('databackd-gaps-onboarding.steps.company.title')" :subtitle="__('databackd-gaps-onboarding.company.intro')">
    <x-form id="onb-company-form" class="ajax-form">
        <div class="row">
            <div class="col-lg-8">
                <x-forms.text fieldId="company_name" fieldName="company_name" :fieldRequired="true"
                              :fieldLabel="__('databackd-gaps-onboarding.company.name')"
                              :fieldPlaceholder="__('databackd-gaps-onboarding.company.name_placeholder')"
                              :fieldValue="$companyModel->company_name"/>
            </div>
            <div class="col-lg-4">
                <x-forms.text fieldId="tax_number" fieldName="tax_number"
                              :fieldLabel="__('databackd-gaps-onboarding.company.tax_number')"
                              :fieldPlaceholder="__('databackd-gaps-onboarding.company.tax_number_placeholder')"
                              :fieldHelp="__('databackd-gaps-onboarding.company.tax_number_help')"
                              :fieldValue="$address->tax_number ?? ''"/>
            </div>
            <div class="col-lg-8">
                <x-forms.textarea fieldId="address" fieldName="address" :fieldRequired="true"
                                  :fieldLabel="__('databackd-gaps-onboarding.company.address')"
                                  :fieldPlaceholder="__('databackd-gaps-onboarding.company.address_placeholder')"
                                  :fieldValue="$address->address ?? $companyModel->address"/>
            </div>
            <div class="col-lg-4">
                <x-forms.text fieldId="company_currency" fieldName="company_currency" fieldReadOnly="true"
                              :fieldLabel="__('databackd-gaps-onboarding.company.currency')"
                              :fieldHelp="__('databackd-gaps-onboarding.company.currency_help')"
                              :fieldValue="$currencyText"/>
                @if ($settingsUrl)
                    <a href="{{ $settingsUrl }}" class="f-13"><i class="fa fa-cog mr-1"></i>@lang('databackd-gaps-onboarding.company.open_settings')</a>
                @endif
            </div>
        </div>

        <div class="onb-actions">
            <span class="onb-spacer"></span>
            <x-forms.button-primary id="onb-company-save" icon="check" data-onb-form="#onb-company-form"
                                    data-onb-submit="{{ route('databackd.gaps.onboarding.company') }}">
                @lang('databackd-gaps-onboarding.actions.save_continue')
            </x-forms.button-primary>
        </div>
    </x-form>
</x-dbk.card>
