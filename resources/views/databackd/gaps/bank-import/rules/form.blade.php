@php
    $L = 'databackd-gaps-bank-import.';
    $isEdit = $rule->exists;
    $selectedAccount = (int)$rule->account_id;
@endphp

<x-form id="rule-form" :method="$isEdit ? 'PUT' : 'POST'" class="ajax-form">
    <div class="modal-header">
        <h5 class="modal-title" id="modelHeading">@lang($isEdit ? $L . 'rules.form.edit_title' : $L . 'rules.form.add_title')</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('databackd-misc.close') }}"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <div class="portlet-body">
            <div class="row">
                <div class="col-12">
                    <x-forms.text fieldId="rule_pattern" fieldName="pattern" :fieldLabel="__($L . 'rules.form.pattern')"
                                  :fieldPlaceholder="__($L . 'rules.form.pattern_placeholder')" :fieldValue="$rule->pattern" :fieldRequired="true"/>
                    <small class="form-text text-muted">@lang($L . 'rules.form.pattern_help')</small>
                </div>
                <div class="col-md-6">
                    <x-forms.select fieldId="rule_match_type" fieldName="match_type" :fieldLabel="__($L . 'rules.form.match_type')" :fieldRequired="true">
                        @foreach (\App\Models\Databackd\FinCounterpartyMapping::MATCH_TYPES as $type)
                            <option value="{{ $type }}" @selected($rule->match_type === $type)>@lang($L . 'match_types.' . $type)</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="col-md-6">
                    <x-forms.select fieldId="rule_direction" fieldName="direction" :fieldLabel="__($L . 'rules.form.direction')" :fieldRequired="true">
                        @foreach (\App\Models\Databackd\FinCounterpartyMapping::DIRECTIONS as $direction)
                            <option value="{{ $direction }}" @selected($rule->direction === $direction)>@lang($L . 'directions.' . $direction)</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="col-12">
                    <x-forms.select fieldId="rule_account" fieldName="account_id" :fieldLabel="__($L . 'rules.form.account')" :fieldRequired="true" :search="true">
                        <option value="">--</option>
                        @foreach (['revenue', 'expense'] as $type)
                            <optgroup label="{{ __($L . 'account_types.' . $type) }}">
                                @foreach ($accounts->where('type', $type) as $account)
                                    <option value="{{ $account->id }}" data-tokens="{{ $account->code }} {{ $account->name }}"
                                            @selected($selectedAccount === (int)$account->id)>{{ $account->code }} — {{ $account->name }}@if (!$account->is_active) ({{ __($L . 'rules.inactive_account') }})@endif</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </x-forms.select>
                    <small class="form-text text-muted">@lang($L . 'rules.form.account_help')</small>
                </div>
                <div class="col-md-6">
                    <x-forms.select fieldId="rule_entity" fieldName="entity_id" :fieldLabel="__($L . 'rules.form.entity')">
                        <option value="">@lang($L . 'rules.all_entities')</option>
                        @foreach ($entities as $entity)
                            <option value="{{ $entity->id }}" @selected((int)$rule->entity_id === (int)$entity->id)>{{ $entity->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="col-md-6">
                    <x-forms.text fieldId="rule_counterparty_name" fieldName="counterparty_name" :fieldLabel="__($L . 'rules.form.counterparty_name')"
                                  :fieldPlaceholder="__($L . 'rules.form.counterparty_name_placeholder')" :fieldValue="$rule->counterparty_name"/>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <x-forms.button-cancel data-dismiss="modal" class="border-0 mr-3">@lang($L . 'rules.cancel')</x-forms.button-cancel>
        <x-forms.button-primary id="save-rule" icon="check">@lang($L . 'rules.save')</x-forms.button-primary>
    </div>
</x-form>

<script>
    init(MODAL_LG);

    $('#save-rule').click(function () {
        $.easyAjax({
            url: "{{ $isEdit ? route('databackd.gaps.bank-import.rules.update', $rule->id) : route('databackd.gaps.bank-import.rules.store') }}",
            container: '#rule-form',
            type: "POST",
            blockUI: true,
            disableButton: true,
            buttonSelector: "#save-rule",
            data: $('#rule-form').serialize(),
            success: function (response) {
                if (response.status == 'success') {
                    window.location.reload();
                }
            }
        });
    });
</script>
