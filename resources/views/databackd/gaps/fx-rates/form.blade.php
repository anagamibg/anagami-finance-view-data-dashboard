@php
    $isEdit = (bool)$rate;
    $t = fn(string $key, array $r = []) => __('databackd-gaps-fx-rates.' . $key, $r);
    // AFV (S12): shown with a decimal comma (1,95583) like the list; the request accepts both separators
    $rateValue = $rate ? str_replace('.', ',', rtrim(rtrim(number_format((float)$rate->rate, 8, '.', ''), '0'), '.')) : '';
@endphp

<x-form id="fx-rate-form" :method="$isEdit ? 'PUT' : 'POST'" class="ajax-form">
    <div class="modal-header">
        <h5 class="modal-title" id="modelHeading">{{ $isEdit ? $t('form.edit_title') : $t('form.add_title') }}</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('form.close') }}"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <div class="portlet-body">
            @if ($isEdit && $rate->source === 'ecb')
                <div class="alert alert-info f-13"><i class="fa fa-info-circle mr-1"></i>{{ $t('form.ecb_edit_note') }}</div>
            @endif

            <div class="row">
                <div class="col-md-4">
                    <x-forms.datepicker fieldId="fx_rate_date" fieldRequired="true" fieldName="rate_date"
                                        :fieldLabel="$t('form.date')"
                                        :fieldPlaceholder="__('placeholders.date')"
                                        :fieldValue="($rate ? $rate->rate_date : now())->format(company()->date_format)"/>
                </div>
                <div class="col-md-4">
                    <x-forms.text fieldId="fx_currency" fieldName="currency" fieldRequired="true"
                                  :fieldLabel="$t('form.currency')"
                                  :fieldPlaceholder="$t('form.currency_placeholder')"
                                  :fieldValue="$rate->currency ?? ''"/>
                </div>
                <div class="col-md-4">
                    <x-forms.text fieldId="fx_rate_value" fieldName="rate" fieldRequired="true"
                                  :fieldLabel="$t('form.rate')"
                                  :fieldPlaceholder="$t('form.rate_placeholder')"
                                  :fieldValue="$rateValue"/>
                </div>
            </div>
            <div class="dbk-fx-preview mt-1" id="fx-rate-preview" aria-live="polite" @if ($rateValue === '') hidden @endif></div>
            <small class="form-text text-muted">{{ $t('form.help') }}</small>
            <small class="form-text text-muted mt-1">{{ $t('form.bgn_note') }}</small>
        </div>
    </div>
    <div class="modal-footer">
        <x-forms.button-cancel data-dismiss="modal" class="border-0 mr-3">{{ $t('form.close') }}</x-forms.button-cancel>
        <x-forms.button-primary id="save-fx-rate" icon="check">{{ $t('form.save') }}</x-forms.button-primary>
    </div>
</x-form>

<script>
    datepicker('#fx_rate_date', {position: 'bl', ...datepickerConfig});

    // live check of the direction: "1 EUR = 1,0850 USD · 1 USD = 0,921659 EUR"
    var fxPreviewText = {!! json_encode($t('form.preview', ['rate' => ':rate', 'currency' => ':currency', 'inverse' => ':inverse']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!};

    function fxPreview() {
        var currency = $('#fx_currency').val() || '…';
        var raw = String($('#fx_rate_value').val() || '').replace(/[\s\u00a0]/g, '');
        var value = parseFloat(raw.indexOf(',') !== -1 && raw.indexOf('.') === -1 ? raw.replace(',', '.') : raw);
        var $box = $('#fx-rate-preview');

        if (!(value > 0) || !isFinite(value)) {
            $box.prop('hidden', true).text('');
            return;
        }

        var format = function (number, digits) {
            return new Intl.NumberFormat('bg-BG', {minimumFractionDigits: 4, maximumFractionDigits: digits}).format(number);
        };

        $box.prop('hidden', false).text(fxPreviewText
            .replace(':rate', format(value, 8)).replace(/:currency/g, currency).replace(':inverse', format(1 / value, 6)));
    }

    $('#fx_currency').on('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '').substring(0, 3);
        fxPreview();
    });

    $('#fx_rate_value').on('input', fxPreview);
    fxPreview();

    $('#save-fx-rate').click(function () {
        $.easyAjax({
            url: "{{ $isEdit ? route('databackd.gaps.fx-rates.update', $rate->id) : route('databackd.gaps.fx-rates.store') }}",
            container: '#fx-rate-form',
            type: "POST",
            blockUI: true,
            disableButton: true,
            buttonSelector: "#save-fx-rate",
            data: $('#fx-rate-form').serialize(),
            success: function (response) {
                if (response.status === 'success') {
                    window.location.reload();
                }
            }
        });
    });
</script>
