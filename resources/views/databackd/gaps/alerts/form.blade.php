@extends('layouts.app')

@push('styles')
    <style>
        /* "px-20" is not a utility of the theme (only p-20) - without this the fields touch the card border */
        .content-wrapper .px-20 { padding-left: 20px !important; padding-right: 20px !important; }
        .ar-block { display: none; }
        .ar-block.is-visible { display: block; }
        .ar-type-help { font-size: 13px; color: #4f5b6b; background: #f7f9fb; border: 1px solid #e4e9f0; border-radius: 6px; padding: 10px 12px; margin-top: 8px; }
        .ar-hint { font-size: 12px; color: #66717f; margin-top: 4px; }
    </style>
@endpush

@section('content')
    @php
        $p = $ruleParams;
        $budgetEntity = fn($b) => $b->entity->name ?? __('databackd-gaps-alerts.scope.group');
        $currentType = old('type', $rule->type);
        $isRatio = $currentType === 'ratio_range';
    @endphp

    <div class="content-wrapper">
        <x-form id="save-alert-rule-form" :method="$isEdit ? 'PUT' : 'POST'">
            <div class="row">

                {{-- Condition --}}
                <div class="col-lg-7 mb-3">
                    <div class="bg-white rounded b-shadow-4 h-100">
                        <h4 class="mb-0 p-20 f-18 font-weight-normal border-bottom-grey">
                            {{ __('databackd-gaps-alerts.form.section_condition') }}
                        </h4>

                        <div class="px-20 pb-3">
                            <x-forms.text :fieldLabel="__('databackd-gaps-alerts.form.name')" fieldName="name" fieldId="ar_name"
                                fieldRequired="true" :fieldValue="$rule->name"
                                :fieldPlaceholder="__('databackd-gaps-alerts.form.name_placeholder')" />

                            <x-forms.select fieldId="ar_type" fieldName="type" :fieldLabel="__('databackd-gaps-alerts.form.type')" fieldRequired="true">
                                @foreach ($ruleTypes as $type)
                                    <option value="{{ $type }}" @selected($currentType === $type)
                                            data-help="{{ __('databackd-gaps-alerts.type_help.' . $type) }}">
                                        {{ __('databackd-gaps-alerts.types.' . $type) }}</option>
                                @endforeach
                            </x-forms.select>
                            <div class="ar-type-help" id="ar_type_help"></div>

                            {{-- ratio_range --}}
                            <div class="ar-block" data-types="ratio_range">
                                <x-forms.select fieldId="ar_ratio" fieldName="params[ratio_id]" :fieldLabel="__('databackd-gaps-alerts.form.ratio')"
                                    fieldRequired="true" :search="true">
                                    <option value="">{{ __('databackd-gaps-alerts.form.choose') }}</option>
                                    @foreach ($ratios as $ratio)
                                        <option value="{{ $ratio->id }}" @selected((int)($p['ratio_id'] ?? 0) === (int)$ratio->id)
                                                data-min="{{ $ratio->target_min }}" data-max="{{ $ratio->target_max }}"
                                                data-target="{{ \App\Services\Databackd\Gaps\Alerts\AlertText::ratioTarget($ratio) }}">
                                            {{ $ratio->name }}</option>
                                    @endforeach
                                </x-forms.select>
                                <div class="ar-hint" id="ar_ratio_target"></div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group my-3">
                                            <x-forms.label fieldId="ar_min" :fieldLabel="__('databackd-gaps-alerts.form.min')" />
                                            <input type="number" step="any" class="form-control height-35 f-14" name="params[min]" id="ar_min"
                                                   value="{{ old('params.min', $p['min'] ?? '') }}">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group my-3">
                                            <x-forms.label fieldId="ar_max" :fieldLabel="__('databackd-gaps-alerts.form.max')" />
                                            <input type="number" step="any" class="form-control height-35 f-14" name="params[max]" id="ar_max"
                                                   value="{{ old('params.max', $p['max'] ?? '') }}">
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <small class="form-text text-muted mt-0">{{ __('databackd-gaps-alerts.form.range_help') }}</small>
                                    </div>
                                </div>
                            </div>

                            {{-- budget_deviation --}}
                            <div class="ar-block" data-types="budget_deviation">
                                <x-forms.select fieldId="ar_budget" fieldName="params[budget_id]" :fieldLabel="__('databackd-gaps-alerts.form.budget')"
                                    fieldRequired="true" :search="true">
                                    <option value="">{{ __('databackd-gaps-alerts.form.choose') }}</option>
                                    @foreach ($budgets as $budget)
                                        <option value="{{ $budget->id }}" @selected((int)($p['budget_id'] ?? 0) === (int)$budget->id)>
                                            {{ $budget->name }} ({{ $budget->year }}, {{ $budgetEntity($budget) }})</option>
                                    @endforeach
                                </x-forms.select>

                                <x-forms.select fieldId="ar_metric" fieldName="params[metric]" :fieldLabel="__('databackd-gaps-alerts.form.metric')">
                                    @foreach (['any', 'revenue', 'expense', 'profit'] as $metric)
                                        <option value="{{ $metric }}" @selected(($p['metric'] ?? 'any') === $metric)>{{ __('databackd-gaps-alerts.form.metrics.' . $metric) }}</option>
                                    @endforeach
                                </x-forms.select>

                                <div class="form-group my-3">
                                    <x-forms.label fieldId="ar_pct" :fieldLabel="__('databackd-gaps-alerts.form.pct')" fieldRequired="true" />
                                    <div class="input-group">
                                        <input type="number" step="any" min="0" class="form-control height-35 f-14" name="params[pct]" id="ar_pct"
                                               value="{{ old('params.pct', $p['pct'] ?? '') }}">
                                        <div class="input-group-append"><span class="input-group-text f-14">%</span></div>
                                    </div>
                                    <small class="form-text text-muted">{{ __('databackd-gaps-alerts.form.pct_help') }}</small>
                                </div>
                            </div>

                            {{-- cash_below / receivables_above --}}
                            <div class="ar-block" data-types="cash_below receivables_above">
                                <div class="form-group my-3">
                                    <x-forms.label fieldId="ar_amount" :fieldLabel="__('databackd-gaps-alerts.form.amount')" fieldRequired="true" />
                                    <div class="input-group">
                                        <input type="number" step="any" class="form-control height-35 f-14" name="params[amount]" id="ar_amount"
                                               value="{{ old('params.amount', $p['amount'] ?? '') }}">
                                        <div class="input-group-append"><span class="input-group-text f-14">{{ company()->currency->currency_code ?? 'EUR' }}</span></div>
                                    </div>
                                    <small class="form-text text-muted" id="ar_amount_help"></small>
                                </div>
                            </div>

                            {{-- scope (the budget defines the scope of a budget deviation rule) --}}
                            <div class="ar-block" data-types="ratio_range cash_below receivables_above">
                                <x-forms.select fieldId="ar_entity" fieldName="entity_id" :fieldLabel="__('databackd-gaps-alerts.form.entity')">
                                    <option value="">{{ __('databackd-gaps-alerts.scope.all') }}</option>
                                    @foreach ($entities as $entity)
                                        <option value="{{ $entity->id }}" @selected((int)old('entity_id', $rule->entity_id) === (int)$entity->id)>{{ $entity->name }}</option>
                                    @endforeach
                                </x-forms.select>
                                <small class="form-text text-muted">{{ __('databackd-gaps-alerts.form.entity_help') }}</small>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Notifications --}}
                <div class="col-lg-5 mb-3">
                    <div class="bg-white rounded b-shadow-4 h-100">
                        <h4 class="mb-0 p-20 f-18 font-weight-normal border-bottom-grey">
                            {{ __('databackd-gaps-alerts.form.section_notify') }}
                        </h4>

                        <div class="px-20 pb-3">
                            <x-forms.select fieldId="ar_severity" fieldName="severity" :fieldLabel="__('databackd-gaps-alerts.form.severity')" fieldRequired="true">
                                @foreach (\App\Models\Databackd\FinAlertRule::SEVERITIES as $severity)
                                    <option value="{{ $severity }}" @selected($rule->severity === $severity)>{{ __('databackd-gaps-alerts.severities.' . $severity) }}</option>
                                @endforeach
                            </x-forms.select>

                            <x-forms.toggle-switch :fieldLabel="__('databackd-gaps-alerts.form.notify_app')" fieldName="notify_app" fieldId="ar_notify_app"
                                :checked="(bool)$rule->notify_app" :fieldHelp="__('databackd-gaps-alerts.form.notify_app_help')" />

                            <x-forms.toggle-switch :fieldLabel="__('databackd-gaps-alerts.form.notify_email')" fieldName="notify_email" fieldId="ar_notify_email"
                                :checked="(bool)$rule->notify_email" :fieldHelp="__('databackd-gaps-alerts.form.notify_email_help')" />

                            <x-forms.textarea :fieldLabel="__('databackd-gaps-alerts.form.recipients')" fieldName="recipients" fieldId="ar_recipients"
                                :fieldValue="old('recipients', $recipientsText)" :fieldPlaceholder="__('databackd-gaps-alerts.form.recipients_placeholder')" />
                            <small class="form-text text-muted mt-0">{{ __('databackd-gaps-alerts.form.recipients_help') }}</small>

                            <x-forms.toggle-switch :fieldLabel="__('databackd-gaps-alerts.form.is_active')" fieldName="is_active" fieldId="ar_is_active"
                                :checked="(bool)$rule->is_active" :fieldHelp="__('databackd-gaps-alerts.form.is_active_help')" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded b-shadow-4">
                <x-form-actions>
                    <x-forms.button-primary id="save-alert-rule" class="mr-3" icon="check">
                        {{ __('databackd-gaps-alerts.form.save') }}
                    </x-forms.button-primary>
                    <x-forms.button-cancel :link="route('databackd.gaps.alerts.index')" class="border-0">@lang('app.cancel')
                    </x-forms.button-cancel>
                </x-form-actions>
            </div>
        </x-form>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var saveUrl = @json($isEdit ? route('databackd.gaps.alerts.update', $rule->id) : route('databackd.gaps.alerts.store'));
            var amountHelp = {
                cash_below: @json(__('databackd-gaps-alerts.form.amount_help_cash')),
                receivables_above: @json(__('databackd-gaps-alerts.form.amount_help_receivables'))
            };
            var rangeFilled = {{ ($p['min'] ?? '') !== '' || ($p['max'] ?? '') !== '' ? 'true' : 'false' }};

            // Show the fields of the chosen type; hidden fields are disabled so they are not submitted
            function applyType() {
                var type = $('#ar_type').val();
                var $selected = $('#ar_type option:selected');

                $('.ar-block').each(function () {
                    var visible = $(this).data('types').split(' ').indexOf(type) !== -1;

                    $(this).toggleClass('is-visible', visible);
                    $(this).find('input, select, textarea').prop('disabled', !visible);
                });

                $('#ar_type_help').text($selected.data('help') || '').toggle(!!$selected.data('help'));
                $('#ar_amount_help').text(amountHelp[type] || '');
                $('.select-picker').selectpicker('refresh');
            }

            // Take the target range of the ratio as the default thresholds
            function applyRatio(force) {
                var $o = $('#ar_ratio option:selected');
                var target = $o.data('target');

                $('#ar_ratio_target').text(target ? @json(__('databackd-gaps-alerts.form.ratio_target')).replace(':target', target) : '');

                if ($o.val() && (force || !rangeFilled)) {
                    $('#ar_min').val($o.data('min') === '' || $o.data('min') === undefined ? '' : $o.data('min'));
                    $('#ar_max').val($o.data('max') === '' || $o.data('max') === undefined ? '' : $o.data('max'));
                }
            }

            $('#ar_type').on('change', applyType);
            $('#ar_ratio').on('changed.bs.select change', function () {
                applyRatio(true);
            });
            $('#ar_min, #ar_max').on('input', function () {
                rangeFilled = true;
            });

            applyType();
            applyRatio(false);

            $('#save-alert-rule').on('click', function () {
                $.easyAjax({
                    url: saveUrl,
                    container: '#save-alert-rule-form',
                    type: 'POST',
                    disableButton: true,
                    blockUI: true,
                    buttonSelector: '#save-alert-rule',
                    data: $('#save-alert-rule-form').serialize()
                });
            });
        });
    </script>
@endpush
