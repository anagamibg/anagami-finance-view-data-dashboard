@php
    $isEdit = $account->exists;
    $typeLocked = $isEdit && $entriesCount > 0;
@endphp

<div class="row">
    <div class="col-sm-12">
        <x-form id="save-account-data-form" :method="$isEdit ? 'PUT' : 'POST'">

            <div class="add-client bg-white rounded">
                <h4 class="mb-0 p-20 f-21 font-weight-normal border-bottom-grey">
                    {{ $pageTitle }}
                </h4>

                <div class="row p-20">
                    <div class="col-md-4">
                        <x-forms.text :fieldLabel="__('databackd-data-accounts.form.code')" fieldName="code" fieldId="account_code"
                            fieldRequired="true" :fieldValue="$account->code" :fieldReadOnly="$isStandard ? 'true' : 'false'"
                            :fieldPlaceholder="__('databackd-data-accounts.form.code_placeholder')"
                            :fieldHelp="$isStandard ? __('databackd-data-accounts.form.code_standard') : __('databackd-data-accounts.form.code_help')" />
                    </div>

                    <div class="col-md-8">
                        <x-forms.text :fieldLabel="__('databackd-data-accounts.form.name')" fieldName="name" fieldId="account_name"
                            fieldRequired="true" :fieldValue="$account->name"
                            :fieldPlaceholder="__('databackd-data-accounts.form.name_placeholder')" />
                    </div>

                    <div class="col-md-6">
                        @if ($typeLocked)
                            <input type="hidden" name="type" value="{{ $account->type }}">
                        @endif
                        <x-forms.select fieldId="account_type" fieldName="type"
                            :fieldLabel="__('databackd-data-accounts.form.type')" fieldRequired="true">
                            @foreach (\App\Models\Databackd\FinAccount::TYPES as $type)
                                <option value="{{ $type }}" @selected($account->type === $type)>
                                    {{ __('databackd-data-common.account_types.' . $type) }}</option>
                            @endforeach
                        </x-forms.select>
                        @if ($typeLocked)
                            <small class="form-text text-muted">{{ __('databackd-data-accounts.form.type_locked') }}</small>
                        @endif
                    </div>

                    <div class="col-md-6">
                        <x-forms.select fieldId="account_subtype" fieldName="subtype"
                            :fieldLabel="__('databackd-data-accounts.form.subtype')">
                            <option value="">{{ __('databackd-data-accounts.form.subtype_none') }}</option>
                            @foreach ($subtypes as $subtype)
                                <option value="{{ $subtype }}" @selected($account->subtype === $subtype)
                                    data-types="{{ collect($subtypesByType)->filter(fn($list) => in_array($subtype, $list, true))->keys()->implode(' ') }}">
                                    {{ __('databackd-data-accounts.subtypes.' . $subtype) }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div class="col-md-12">
                        <x-forms.select fieldId="account_parent_id" fieldName="parent_id"
                            :fieldLabel="__('databackd-data-accounts.form.parent')" search="true">
                            <option value="">{{ __('databackd-data-accounts.form.parent_none') }}</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}" @selected($account->parent_id === $parent->id)>
                                    {{ $parent->code }} - {{ $parent->name }}</option>
                            @endforeach
                        </x-forms.select>
                        <small class="form-text text-muted">{{ __('databackd-data-accounts.form.parent_help') }}</small>
                    </div>

                    <div class="col-md-12">
                        <x-forms.toggle-switch :fieldLabel="__('databackd-data-accounts.form.is_active')" fieldName="is_active"
                            fieldId="account_is_active" :checked="(bool)$account->is_active"
                            :fieldHelp="__('databackd-data-accounts.form.is_active_help')" />
                    </div>

                    <div class="col-md-12 mt-2" id="contra-note"
                        @if (!\App\Models\Databackd\FinAccount::isContraCode((string)$account->code, $account->parent_id ? (string)$account->parent?->code : null)) style="display:none" @endif>
                        <div class="alert alert-info f-13 mb-0">
                            <i class="fa fa-info-circle mr-1"></i>{{ __('databackd-data-accounts.contra_note') }}
                        </div>
                    </div>

                    @if ($isEdit && $entriesCount > 0)
                        <div class="col-md-12 mt-2">
                            <p class="f-13 text-lightest mb-0">
                                <i class="fa fa-list mr-1"></i>{{ __('databackd-data-accounts.form.entries_info', ['count' => number_format($entriesCount, 0, ',', ' ')]) }}
                            </p>
                        </div>
                    @endif
                </div>

                <x-form-actions>
                    <x-forms.button-primary id="save-account-form" class="mr-3" icon="check">@lang('app.save')
                    </x-forms.button-primary>
                    <x-forms.button-cancel :link="route('databackd.data.accounts.index')" class="border-0">@lang('app.cancel')
                    </x-forms.button-cancel>
                </x-form-actions>
            </div>
        </x-form>
    </div>
</div>

@include('databackd.data.entries._form-ux')

<script>
    $(document).ready(function() {
        var formSelector = '#save-account-data-form';
        var isEdit = {{ $isEdit ? 'true' : 'false' }};
        var contraCodes = @json(\App\Models\Databackd\FinAccount::CONTRA_CODES);
        var typeByGroup = @json($typeByGroup);
        var saveUrl = "{{ $isEdit ? route('databackd.data.accounts.update', $account->id) : route('databackd.data.accounts.store') }}";
        var busy = false;

        $('#account_code, #account_name').attr('data-dbk-rule', 'required');

        // Only the subtypes of the chosen type (the server checks the same)
        var filterSubtypes = function() {
            var type = $('#account_type').val();
            var $subtype = $('#account_subtype');

            $subtype.find('option[data-types]').each(function() {
                var allowed = String($(this).data('types')).split(' ').indexOf(type) !== -1;
                $(this).prop('disabled', !allowed).toggle(allowed);
            });

            if ($subtype.find('option:selected').prop('disabled')) {
                $subtype.val('');
            }

            $subtype.selectpicker('refresh');
        };

        $('#account_type').on('change', filterSubtypes);

        // Suggest the account type from the first digit of a new account's code
        $('#account_code').on('input', function() {
            var code = $.trim($(this).val());
            // 241 and its analytic accounts (2411, 241-1) - FinAccount::isContraCode()
            $('#contra-note').toggle(contraCodes.indexOf(code) !== -1 || (/^\d{3}./.test(code) && contraCodes.indexOf(code.substr(0, 3)) !== -1));

            if (!isEdit && code.length > 0 && typeByGroup[code.charAt(0)] && !$('#account_type').prop('disabled')) {
                $('#account_type').val(typeByGroup[code.charAt(0)]).selectpicker('refresh');
                filterSubtypes();
            }
        });

        var save = function() {
            if (busy || !dbkFormUx.check(formSelector)) {
                return;
            }

            busy = true;
            dbkFormUx.afterServerErrors(formSelector, saveUrl, function() {
                busy = false;
            });

            $.easyAjax({
                url: saveUrl,
                container: formSelector,
                type: "POST",
                disableButton: true,
                blockUI: true,
                buttonSelector: "#save-account-form",
                data: $(formSelector).serialize(),
                success: function(response) {
                    if (response.status === 'success') {
                        window.location.href = "{{ route('databackd.data.accounts.index') }}";
                    }
                }
            });
        };

        $('#save-account-form').click(save);
        dbkFormUx.attach(formSelector, {submit: save});

        init($(formSelector).closest(RIGHT_MODAL).length ? RIGHT_MODAL : '.content-wrapper');

        @if ($typeLocked)
            $('#account_type').prop('disabled', true).selectpicker('refresh');
        @endif

        filterSubtypes();
    });
</script>
