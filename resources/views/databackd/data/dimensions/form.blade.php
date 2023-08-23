<x-form id="dimension-form" :method="$dimension ? 'PUT' : 'POST'" class="ajax-form">
    <div class="modal-header">
        <h5 class="modal-title" id="modelHeading">
            @if ($dimension)
                @lang('databackd-data-dimensions.edit_dimension_title', ['type' => __('databackd-data-dimensions.type_names.' . $type)])
            @else
                @lang('databackd-data-dimensions.add_dimension_title', ['type' => __('databackd-data-dimensions.type_names.' . $type)])
            @endif
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('databackd-misc.close') }}"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <div class="portlet-body">
            <input type="hidden" name="type" value="{{ $type }}">
            <p class="f-13 text-lightest mb-0">@lang('databackd-data-dimensions.type_hints.' . $type)</p>

            <div class="row">
                <div class="col-lg-8">
                    <x-forms.text fieldId="dimension_name" fieldName="name" fieldRequired="true"
                                  :fieldLabel="__('databackd-data-dimensions.name')"
                                  :fieldPlaceholder="__('databackd-data-dimensions.name_placeholder')"
                                  :fieldValue="$dimension->name ?? ''"/>
                </div>
                <div class="col-lg-4">
                    <x-forms.text fieldId="dimension_code" fieldName="code"
                                  :fieldLabel="__('databackd-data-dimensions.code')"
                                  :fieldPlaceholder="__('databackd-data-dimensions.code_placeholder')"
                                  :fieldValue="$dimension->code ?? ''"/>
                </div>
            </div>
            <small class="form-text text-muted">@lang('databackd-data-dimensions.code_help')</small>
        </div>
    </div>
    <div class="modal-footer">
        <x-forms.button-cancel data-dismiss="modal" class="border-0 mr-3">@lang('databackd-data-dimensions.close')</x-forms.button-cancel>
        <x-forms.button-primary id="save-dimension" icon="check">@lang('databackd-data-dimensions.save')</x-forms.button-primary>
    </div>
</x-form>

@include('databackd.data.entries._form-ux')

<script>
    (function () {
        var formSelector = '#dimension-form';
        var saveUrl = "{{ $dimension ? route('databackd.data.dimensions.update', $dimension->id) : route('databackd.data.dimensions.store') }}";
        var busy = false;

        $('#dimension_name').attr('data-dbk-rule', 'required');

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
                buttonSelector: "#save-dimension",
                data: $(formSelector).serialize(),
                success: function (response) {
                    if (response.status == 'success') {
                        window.location.href = "{{ route('databackd.data.dimensions.index') }}?type=" + response.type;
                    }
                }
            });
        };

        $('#save-dimension').click(save);
        dbkFormUx.attach(formSelector, {submit: save});

        // the modal is shown already filled: put the cursor in the name
        setTimeout(function () {
            $('#dimension_name').trigger('focus');
        }, 300);
    })();
</script>
