<x-form id="entity-form" :method="$entity ? 'PUT' : 'POST'" class="ajax-form">
    <div class="modal-header">
        <h5 class="modal-title" id="modelHeading">
            @lang($entity ? 'databackd-data-dimensions.edit_entity_title' : 'databackd-data-dimensions.add_entity_title')
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('databackd-misc.close') }}"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <div class="portlet-body">
            <div class="row">
                <div class="col-lg-8">
                    <x-forms.text fieldId="entity_name" fieldName="name" fieldRequired="true"
                                  :fieldLabel="__('databackd-data-dimensions.entity_name')"
                                  :fieldPlaceholder="__('databackd-data-dimensions.entity_name_placeholder')"
                                  :fieldValue="$entity->name ?? ''"/>
                </div>
                <div class="col-lg-4">
                    <x-forms.text fieldId="entity_eik" fieldName="eik"
                                  :fieldLabel="__('databackd-data-dimensions.eik')"
                                  :fieldPlaceholder="__('databackd-data-dimensions.eik_placeholder')"
                                  :fieldValue="$entity->eik ?? ''"/>
                </div>
                <div class="col-lg-6">
                    <x-forms.text fieldId="entity_country" fieldName="country" fieldRequired="true"
                                  :fieldLabel="__('databackd-data-dimensions.country')"
                                  :fieldHelp="__('databackd-data-dimensions.country_help')"
                                  :fieldValue="$entity->country ?? 'BG'"/>
                </div>
                <div class="col-lg-6">
                    {{-- S12/A07: only currencies with a known rate (entries of the entity are converted with it) --}}
                    <x-forms.select fieldId="entity_currency" fieldName="currency" fieldRequired="true"
                                    :fieldLabel="__('databackd-data-dimensions.currency')">
                        @foreach ($currencyCodes as $code)
                            <option value="{{ $code }}" @selected(($entity->currency ?? \App\Http\Controllers\Databackd\Data\FinEntryController::baseCurrency()) === $code)>{{ $code }}</option>
                        @endforeach
                    </x-forms.select>
                    <small class="form-text text-muted">@lang('databackd-data-dimensions.currency_help')</small>
                </div>
                <div class="col-lg-8">
                    <x-forms.select fieldId="entity_parent_id" fieldName="parent_id" :search="true"
                                    :fieldLabel="__('databackd-data-dimensions.parent')">
                        <option value="">@lang('databackd-data-dimensions.parent_none')</option>
                        @foreach ($parentOptions as $option)
                            <option value="{{ $option->id }}" @selected($entity && $entity->parent_id == $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </x-forms.select>
                    <small class="form-text text-muted">@lang('databackd-data-dimensions.parent_self_help')</small>
                </div>
                <div class="col-lg-4">
                    <x-forms.number fieldId="entity_ownership_pct" fieldName="ownership_pct"
                                    :fieldLabel="__('databackd-data-dimensions.ownership_pct')"
                                    :fieldValue="$entity ? rtrim(rtrim(number_format($entity->ownership_pct, 2, '.', ''), '0'), '.') : 100"
                                    minValue="0" maxValue="100" class="mb-0"/>
                </div>
                <div class="col-lg-12">
                    <small class="form-text text-muted mt-0 mb-2">@lang('databackd-data-dimensions.ownership_pct_help')</small>
                </div>
                <div class="col-lg-4">
                    <x-forms.number fieldId="entity_sort" fieldName="sort"
                                    :fieldLabel="__('databackd-data-dimensions.sort')"
                                    :fieldHelp="__('databackd-data-dimensions.sort_help')"
                                    :fieldValue="$nextSort" minValue="0"/>
                </div>
                <div class="col-lg-4">
                    <x-forms.toggle-switch fieldId="entity_is_active" fieldName="is_active"
                                           :fieldLabel="__('databackd-data-dimensions.is_active')"
                                           :checked="$entity ? $entity->is_active : true"/>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <x-forms.button-cancel data-dismiss="modal" class="border-0 mr-3">@lang('databackd-data-dimensions.close')</x-forms.button-cancel>
        <x-forms.button-primary id="save-entity" icon="check">@lang('databackd-data-dimensions.save')</x-forms.button-primary>
    </div>
</x-form>

@include('databackd.data.entries._form-ux')

<script>
    init(MODAL_LG);

    (function () {
        var formSelector = '#entity-form';
        var saveUrl = "{{ $entity ? route('databackd.data.entities.update', $entity->id) : route('databackd.data.entities.store') }}";
        var busy = false;

        $('#entity_name, #entity_country').attr('data-dbk-rule', 'required');

        $(formSelector).on('input', '#entity_country', function () {
            this.value = this.value.toUpperCase();
        });

        var save = function () {
            if (busy || !dbkFormUx.check(formSelector)) {
                return;
            }

            busy = true;
            dbkFormUx.afterServerErrors(formSelector, saveUrl, function () {
                busy = false;
            });

            $.easyAjax({
                url: saveUrl,
                container: formSelector,
                type: "POST",
                blockUI: true,
                disableButton: true,
                buttonSelector: "#save-entity",
                data: $(formSelector).serialize(),
                success: function (response) {
                    if (response.status == 'success') {
                        window.location.reload();
                    }
                }
            });
        };

        $('#save-entity').click(save);
        dbkFormUx.attach(formSelector, {submit: save});
    })();
</script>
