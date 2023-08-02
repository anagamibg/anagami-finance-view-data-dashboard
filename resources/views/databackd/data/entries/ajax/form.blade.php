@php
    $isEdit = $entry->exists;
    // transferred from an invoice/expense: date, amount, currency, counterparty and document no. follow the document (read-only)
    $fromDocument = $isEdit && in_array($entry->source, \App\Services\Databackd\Data\InvoiceExpenseSync::SOURCES, true);
    $formId = 'save-fin-entry-form';
    $dimensionFields = [
        'region_id' => ['region', $entry->region_id],
        'client_segment_id' => ['client_segment', $entry->client_segment_id],
        'product_group_id' => ['product_group', $entry->product_group_id],
        'cost_center_id' => ['cost_center', $entry->cost_center_id],
    ];
    $kindFor = fn($type) => in_array($type, ['asset', 'liability', 'equity'], true) ? 'balance' : 'flow';
    // AFV (S12/A07): Enter = "save and add next" in the create modal (fast keyboard entry), Ctrl/Cmd+Enter = save
    $enterAddsNext = !$isEdit && $inModal;
    $selectedCurrency = $entry->currency ?: ($entities->firstWhere('id', $entry->entity_id)->currency ?? $entities->first()->currency ?? $baseCurrency);
@endphp

<div class="row">
    <div class="col-sm-12">
        <x-form id="{{ $formId }}" :method="$isEdit ? 'PUT' : 'POST'">

            <div class="add-client bg-white rounded">
                <h4 class="mb-0 p-20 f-18 font-weight-normal border-bottom-grey">
                    @lang('databackd-data-entries.form.section_main')
                </h4>

                @if ($isEdit && $entry->source !== 'manual')
                    <div class="px-20 pt-3 f-13 text-dark-grey">
                        <i class="fa fa-info-circle mr-1"></i>
                        {{ __('databackd-data-entries.form.imported_note', ['source' => __('databackd-data-common.sources.' . $entry->source)]) }}
                        @if ($fromDocument)
                            {{ __('databackd-data-sync.locked_hint') }}
                        @endif
                    </div>
                @endif

                @if (!$isEdit && $repeated)
                    <div class="px-20 pt-3 f-13 text-dark-grey" id="repeated-note">
                        <i class="fa fa-redo mr-1"></i>@lang('databackd-data-entries.form.repeated_note')
                    </div>
                @endif

                <div class="row p-20">
                    <div class="col-md-6">
                        @if ($fromDocument)
                            <x-forms.text fieldId="entry_date" fieldRequired="true" fieldName="entry_date" fieldReadOnly="true"
                                :fieldLabel="__('databackd-data-common.fields.entry_date')"
                                :fieldValue="$entry->entry_date->format(company()->date_format)" />
                        @else
                            <x-forms.datepicker fieldId="entry_date" fieldRequired="true" fieldName="entry_date"
                                :fieldLabel="__('databackd-data-common.fields.entry_date')"
                                :fieldPlaceholder="__('placeholders.date')"
                                :fieldValue="$entry->entry_date ? $entry->entry_date->format(company()->date_format) : now()->format(company()->date_format)" />
                        @endif
                    </div>

                    <div class="col-md-6">
                        <x-forms.select fieldId="entity_id" fieldName="entity_id" fieldRequired="true"
                            :fieldLabel="__('databackd-data-common.fields.entity')">
                            @foreach ($entities as $entity)
                                <option value="{{ $entity->id }}" data-currency="{{ $entity->currency }}"
                                    @selected($isEdit || $entry->entity_id ? $entry->entity_id == $entity->id : $loop->first)>
                                    {{ $entity->name }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div class="col-md-12">
                        <x-forms.select fieldId="account_id" fieldName="account_id" fieldRequired="true" search="true"
                            :fieldLabel="__('databackd-data-entries.columns.account')">
                            <option value="" data-kind="">{{ __('databackd-data-entries.form.select_account') }}</option>
                            @foreach ($accounts->groupBy('type') as $type => $items)
                                <optgroup label="{{ __('databackd-data-common.account_types.' . $type) }}">
                                    @foreach ($items as $account)
                                        <option value="{{ $account->id }}" data-kind="{{ $kindFor($account->type) }}"
                                            data-pnl="{{ in_array($account->type, ['revenue', 'expense'], true) ? 1 : 0 }}"
                                            @selected($entry->account_id == $account->id)>
                                            {{ $account->code }} · {{ $account->name }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </x-forms.select>
                        <small class="form-text text-muted">@lang('databackd-data-entries.form.account_hint')</small>
                    </div>

                    <div class="col-md-4">
                        <x-forms.select fieldId="kind" fieldName="kind" fieldRequired="true"
                            :fieldLabel="__('databackd-data-common.fields.kind')">
                            @foreach (\App\Models\Databackd\FinEntry::KINDS as $kind)
                                <option value="{{ $kind }}" @selected(($entry->kind ?: 'flow') == $kind)>
                                    {{ __('databackd-data-common.kinds.' . $kind) }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group my-3">
                            <x-forms.label fieldId="amount" fieldRequired="true"
                                :fieldLabel="__('databackd-data-common.fields.amount')" />
                            {{-- text, not number: "1 234,56" and a paste from Excel are accepted (normalised by StoreFinEntry) --}}
                            <input type="text" inputmode="decimal" autocomplete="off" class="form-control height-35 f-14 text-right dbk-num"
                                name="amount" id="amount" data-dbk-rule="required amount"
                                value="{{ $isEdit ? number_format((float)$entry->amount, 2, ',', "\u{00A0}") : '' }}"
                                placeholder="{{ __('databackd-data-entries.form.placeholder_amount') }}" @readonly($fromDocument)>
                        </div>
                    </div>

                    <div class="col-md-4">
                        @if ($fromDocument)
                            <x-forms.text fieldId="currency" fieldName="currency" fieldRequired="true" fieldReadOnly="true"
                                :fieldLabel="__('databackd-data-common.fields.currency')" :fieldValue="$entry->currency" />
                        @else
                            <x-forms.select fieldId="currency" fieldName="currency" fieldRequired="true"
                                :fieldLabel="__('databackd-data-common.fields.currency')">
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}" @selected($selectedCurrency == $code)>{{ $code }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                    </div>

                    <div class="col-md-12">
                        <small class="form-text text-muted" id="kind-hint">@lang('databackd-data-entries.form.amount_hint')</small>
                        <small class="form-text text-muted">@lang('databackd-data-entries.form.currency_hint')</small>
                    </div>
                </div>

                <h4 class="mb-0 px-20 py-3 f-18 font-weight-normal border-top-grey border-bottom-grey">
                    @lang('databackd-data-entries.form.section_dimensions')
                </h4>

                <div class="row p-20">
                    @foreach ($dimensionFields as $field => [$type, $selected])
                        <div class="col-md-6">
                            <x-forms.select :fieldId="$field" :fieldName="$field" search="true"
                                :fieldLabel="__('databackd-data-common.fields.' . $type)">
                                <option value="">{{ __('databackd-data-entries.form.none') }}</option>
                                @foreach ($dimensions->get($type, collect()) as $dimension)
                                    <option value="{{ $dimension->id }}" @selected($selected == $dimension->id)>{{ $dimension->name }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>
                    @endforeach
                </div>

                <h4 class="mb-0 px-20 py-3 f-18 font-weight-normal border-top-grey border-bottom-grey">
                    @lang('databackd-data-entries.form.section_details')
                </h4>

                <div class="row p-20">
                    <div class="col-md-6">
                        <x-forms.text fieldId="counterparty_name" fieldName="counterparty_name"
                            :fieldLabel="__('databackd-data-common.fields.counterparty_name')"
                            :fieldPlaceholder="__('databackd-data-entries.form.placeholder_counterparty')"
                            :fieldValue="$entry->counterparty_name" :fieldReadOnly="$fromDocument ? 'true' : 'false'" />
                    </div>

                    <div class="col-md-6">
                        <x-forms.text fieldId="document_no" fieldName="document_no"
                            :fieldLabel="__('databackd-data-common.fields.document_no')"
                            :fieldPlaceholder="__('databackd-data-entries.form.placeholder_document')"
                            :fieldValue="$entry->document_no" :fieldReadOnly="$fromDocument ? 'true' : 'false'" />
                    </div>

                    <div class="col-md-12">
                        <x-forms.textarea fieldId="description" fieldName="description"
                            :fieldLabel="__('databackd-data-common.fields.description')"
                            :fieldPlaceholder="__('databackd-data-entries.form.placeholder_description')"
                            :fieldValue="$entry->description" />
                    </div>
                </div>

                <x-form-actions>
                    <x-forms.button-primary id="save-fin-entry" class="mr-3" icon="check">
                        @lang('databackd-data-entries.form.save')
                    </x-forms.button-primary>

                    @unless ($isEdit)
                        <x-forms.button-secondary id="save-and-new" class="mr-3" icon="plus">
                            @lang('databackd-data-entries.form.save_and_new')
                        </x-forms.button-secondary>
                    @endunless

                    <x-forms.button-cancel :link="route('databackd.data.entries.index')" class="border-0">
                        @lang('databackd-data-entries.form.cancel')
                    </x-forms.button-cancel>

                    <span class="dbk-data-keys d-none d-md-inline">
                        {{ $enterAddsNext ? __('databackd-data-entries.form.keys_hint_create') : __('databackd-data-entries.form.keys_hint') }}
                    </span>
                </x-form-actions>
            </div>
        </x-form>
    </div>
</div>

@include('databackd.data.entries._form-ux')

<script>
    $(document).ready(function () {
        const formSelector = '#{{ $formId }}';
        const saveUrl = "{{ $isEdit ? route('databackd.data.entries.update', $entry->id) : route('databackd.data.entries.store') }}";
        const inModal = @json((bool)$inModal);
        const isEdit = @json($isEdit);
        const enterAddsNext = @json($enterAddsNext);
        const kindNames = @json(__('databackd-data-entries.form.kind_names'));
        const kindHint = @json(__('databackd-data-entries.form.kind_hint'));
        const kindPnlHint = @json(__('databackd-data-entries.form.kind_pnl_hint'));
        const amountHint = @json(__('databackd-data-entries.form.amount_hint'));
        let busy = false;

        if (!@json($fromDocument)) {
            datepicker('#entry_date', {
                position: 'bl',
                ...datepickerConfig
            });
        }

        $('#entry_date, #entity_id, #account_id, #currency').attr('data-dbk-rule', 'required');

        // Suggest the kind from the account type: balance sheet accounts -> balance, P&L accounts -> flow only
        // (a "balance" on a revenue / expense account is ignored by the reports, the server rejects it as well)
        const applyAccount = function (suggest) {
            var $option = $('#account_id').find('option:selected');
            var kind = $option.data('kind');
            var pnl = String($option.data('pnl')) === '1';

            $('#kind option[value="balance"]').prop('disabled', pnl);

            if (kind && (suggest || pnl)) {
                $('#kind').val(kind);
            }

            $('#kind').selectpicker('refresh');

            if (kind) {
                $('#kind-hint').text(pnl ? kindPnlHint : kindHint.replace(':kind', kindNames[kind]));
            } else {
                $('#kind-hint').text(amountHint);
            }
        };

        $('#account_id').on('change', function () {
            applyAccount(true);
        });

        applyAccount(false);

        // Default the currency to the one of the legal entity (new entries only)
        $('#entity_id').on('change', function () {
            var currency = $(this).find('option:selected').data('currency');

            if (!isEdit && currency && $('#currency option[value="' + currency + '"]').length) {
                $('#currency').selectpicker('val', currency);
            }
        });

        const saveEntry = function (addMore) {
            if (busy) {
                return; // no double submit (button click + Enter)
            }

            if (!dbkFormUx.check(formSelector)) {
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
                disableButton: true,
                blockUI: true,
                buttonSelector: addMore ? '#save-and-new' : '#save-fin-entry',
                data: $(formSelector).serialize(),
                success: function (response) {
                    if (response.status !== 'success') {
                        return;
                    }

                    if (!inModal) {
                        window.location.href = response.redirectUrl;
                        return;
                    }

                    if (typeof showTable === 'function') {
                        showTable();
                    }

                    if (addMore) {
                        // keep the values that usually repeat for the next entry (not the amount, counterparty, document, note)
                        var keep = {};
                        $.each(['entry_date', 'entity_id', 'account_id', 'kind', 'currency', 'region_id', 'client_segment_id', 'product_group_id', 'cost_center_id'], function (i, name) {
                            keep[name] = $(formSelector).find('[name="' + name + '"]').val() || '';
                        });

                        $.easyAjax({
                            url: "{{ route('databackd.data.entries.create') }}",
                            type: "GET",
                            blockUI: true,
                            data: keep,
                            success: function (form) {
                                if (form.status == 'success') {
                                    $(RIGHT_MODAL_CONTENT).html(form.html);
                                    setTimeout(function () {
                                        $('#amount').trigger('focus');
                                    }, 50);
                                }
                            }
                        });
                    } else {
                        closeTaskDetail();
                    }
                }
            });
        };

        $('#save-fin-entry').click(function () {
            saveEntry(false);
        });

        $('#save-and-new').click(function () {
            saveEntry(true);
        });

        dbkFormUx.attach(formSelector, {
            submit: function (withModifier) {
                saveEntry(enterAddsNext && !withModifier);
            }
        });

        init(inModal ? RIGHT_MODAL : '.content-wrapper');

        if (!isEdit && @json($repeated)) {
            setTimeout(function () {
                $('#amount').trigger('focus');
            }, 50);
        }
    });
</script>
