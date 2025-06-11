{{-- Step 2: legal entities. Variables: $entities, $entityNames, $hasEntities, $maxSubsidiaries, $defaultName, $defaultEik, $entitiesUrl, $prevUrl --}}
@php
    $root = $entities->firstWhere('parent_id', null);
@endphp

<x-dbk.card :title="__('databackd-gaps-onboarding.steps.entities.title')" :subtitle="__('databackd-gaps-onboarding.entities.intro')" class="mb-3">
    <h6 class="f-14 f-w-500 mb-2">@lang('databackd-gaps-onboarding.entities.existing')</h6>
    @if ($hasEntities)
        <div class="dbk-table-wrap">
            <table class="dbk-table dbk-table--compact">
                <thead>
                <tr>
                    <th>@lang('databackd-gaps-onboarding.entities.columns.name')</th>
                    <th>@lang('databackd-gaps-onboarding.entities.columns.eik')</th>
                    <th>@lang('databackd-gaps-onboarding.entities.columns.country')</th>
                    <th>@lang('databackd-gaps-onboarding.entities.columns.currency')</th>
                    <th>@lang('databackd-gaps-onboarding.entities.columns.parent')</th>
                    <th class="num text-right">@lang('databackd-gaps-onboarding.entities.columns.share')</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($entities as $entity)
                    <tr>
                        <td>{{ $entity->name }}</td>
                        <td class="dbk-num">{{ $entity->eik ?: '—' }}</td>
                        <td>{{ $entity->country }}</td>
                        <td>{{ $entity->currency }}</td>
                        <td>{{ $entity->parent_id ? ($entityNames[$entity->parent_id] ?? '—') : '—' }}</td>
                        <td class="num text-right dbk-num">{{ $entity->parent_id ? number_format($entity->ownership_pct, 2, ',', ' ') . ' %' : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <x-dbk.empty icon="building" size="sm" :title="__('databackd-gaps-onboarding.entities.none')" :text="__('databackd-gaps-onboarding.entities.none_text')"/>
    @endif
</x-dbk.card>

<x-dbk.card class="mb-3">
    <x-form id="onb-entities-form" class="ajax-form">
        @unless ($hasEntities)
            <h6 class="f-14 f-w-500 mb-0">@lang('databackd-gaps-onboarding.entities.main_title')</h6>
            <p class="f-13 text-dark-grey mb-2">@lang('databackd-gaps-onboarding.entities.main_help')</p>
            <div class="row">
                <div class="col-lg-5">
                    <x-forms.text fieldId="main_name" fieldName="main_name" :fieldRequired="true"
                                  :fieldLabel="__('databackd-gaps-onboarding.entities.name')"
                                  :fieldValue="$defaultName"/>
                </div>
                <div class="col-lg-3">
                    <x-forms.text fieldId="main_eik" fieldName="main_eik"
                                  :fieldLabel="__('databackd-gaps-onboarding.entities.eik')"
                                  :fieldValue="$defaultEik"/>
                </div>
                <div class="col-lg-2 col-6">
                    <x-forms.text fieldId="main_country" fieldName="main_country"
                                  :fieldLabel="__('databackd-gaps-onboarding.entities.country')"
                                  fieldValue="BG"/>
                </div>
                <div class="col-lg-2 col-6">
                    <x-forms.text fieldId="main_currency" fieldName="main_currency"
                                  :fieldLabel="__('databackd-gaps-onboarding.entities.currency')"
                                  fieldValue="EUR"/>
                </div>
            </div>
            <hr class="my-3">
        @endunless

        <h6 class="f-14 f-w-500 mb-0">@lang('databackd-gaps-onboarding.entities.subsidiaries_title')</h6>
        <p class="f-13 text-dark-grey mb-2">
            @if ($root)
                {{ __('databackd-gaps-onboarding.entities.subsidiaries_help', ['name' => $root->name]) }}
            @else
                @lang('databackd-gaps-onboarding.entities.subsidiaries_help_new')
            @endif
        </p>

        <div id="onb-subs"></div>

        <button type="button" class="btn btn-link f-14 px-0" id="onb-add-sub">
            <i class="fa fa-plus mr-1"></i>@lang('databackd-gaps-onboarding.actions.add_subsidiary')
        </button>
        <p class="f-12 text-lightest mb-0">{{ __('databackd-gaps-onboarding.entities.limit', ['count' => $maxSubsidiaries]) }}</p>

        <div class="onb-actions">
            @if ($prevUrl)
                <x-forms.link-secondary :link="$prevUrl" icon="arrow-left">@lang('databackd-gaps-onboarding.actions.back')</x-forms.link-secondary>
            @endif
            @if ($entitiesUrl)
                <a href="{{ $entitiesUrl }}" class="f-14"><i class="fa fa-sitemap mr-1"></i>@lang('databackd-gaps-onboarding.entities.manage')</a>
            @endif
            <span class="onb-spacer"></span>
            <x-forms.button-secondary id="onb-entities-skip" icon="forward" data-onb-post="{{ route('databackd.gaps.onboarding.step', 'entities') }}"
                                      data-onb-data='{"status":"skipped"}'>
                @lang('databackd-gaps-onboarding.actions.skip')
            </x-forms.button-secondary>
            <x-forms.button-primary id="onb-entities-save" icon="check" data-onb-form="#onb-entities-form"
                                    data-onb-submit="{{ route('databackd.gaps.onboarding.entities') }}">
                @lang('databackd-gaps-onboarding.actions.save_continue')
            </x-forms.button-primary>
        </div>
    </x-form>
</x-dbk.card>

<template id="onb-sub-template">
    <div class="onb-sub-row">
        <div class="row">
            <div class="col-lg-4">
                <div class="form-group">
                    <label>@lang('databackd-gaps-onboarding.entities.name')</label>
                    <input type="text" class="form-control height-35 f-14" name="sub_name[]" maxlength="191">
                </div>
            </div>
            <div class="col-lg-2 col-6">
                <div class="form-group">
                    <label>@lang('databackd-gaps-onboarding.entities.eik')</label>
                    <input type="text" class="form-control height-35 f-14" name="sub_eik[]" maxlength="20">
                </div>
            </div>
            <div class="col-lg-2 col-6">
                <div class="form-group">
                    <label>@lang('databackd-gaps-onboarding.entities.country')</label>
                    <input type="text" class="form-control height-35 f-14" name="sub_country[]" value="BG" maxlength="2">
                </div>
            </div>
            <div class="col-lg-2 col-6">
                <div class="form-group">
                    <label>@lang('databackd-gaps-onboarding.entities.currency')</label>
                    <input type="text" class="form-control height-35 f-14" name="sub_currency[]" value="EUR" maxlength="3">
                </div>
            </div>
            <div class="col-lg-2 col-6">
                <div class="form-group">
                    <label>@lang('databackd-gaps-onboarding.entities.share')</label>
                    <div class="d-flex align-items-center">
                        <input type="number" class="form-control height-35 f-14" name="sub_pct[]" value="100" min="0" max="100" step="0.01">
                        <button type="button" class="btn btn-link text-danger px-2 onb-remove-sub" title="{{ __('databackd-gaps-onboarding.actions.remove') }}" aria-label="{{ __('databackd-gaps-onboarding.actions.remove') }}">
                            <i class="fa fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

@push('scripts')
    <script>
        $(function () {
            var max = {{ (int)$maxSubsidiaries }};
            var $subs = $('#onb-subs');
            var template = document.getElementById('onb-sub-template');

            function refresh() {
                $('#onb-add-sub').toggle($subs.children().length < max);
            }

            function addRow() {
                if ($subs.children().length >= max) {
                    return;
                }

                $subs.append(document.importNode(template.content, true));
                refresh();
            }

            $('#onb-add-sub').on('click', addRow);

            $subs.on('click', '.onb-remove-sub', function () {
                $(this).closest('.onb-sub-row').remove();
                refresh();
            });

            $('#onb-entities-form').on('input', '[name="main_country"], [name="main_currency"], [name="sub_country[]"], [name="sub_currency[]"]', function () {
                this.value = this.value.toUpperCase();
            });

            addRow();
        });
    </script>
@endpush
