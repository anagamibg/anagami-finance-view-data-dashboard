{{--
    Anagami Finance View (S12): "Undo the import" with a confirmation that names the consequence (how many entries are deleted, reports change).
    Used by the history list, the batch detail and the result of the import wizard. Delegated click handler on body.
    Variables: $selector (CSS selector of the button / link), $batchId (int|null - null: data-batch-id of the clicked element),
               $entries (int|null - null: data-entries of the clicked element), $redirect (string|null - URL after the undo, default: reload)
--}}
@php($undoText = __('databackd-data-batches.undo.confirm_text_count', ['count' => ':count']))
<script>
    $(function () {
        $('body').on('click', @json($selector), function (e) {
            e.preventDefault();
            var $button = $(this);
            var id = @json($batchId ?? null) || $button.data('batch-id');
            var entries = @json($entries ?? null);

            if (entries === null) {
                entries = parseInt($button.data('entries'), 10) || 0;
            }

            var count = String(entries).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

            Swal.fire({
                title: @json(__('databackd-data-batches.undo.confirm_title')),
                text: @json($undoText).replace(':count', count),
                icon: 'warning',
                showCancelButton: true,
                focusConfirm: false,
                focusCancel: true,
                confirmButtonText: @json(__('databackd-data-batches.undo.confirm_button')),
                cancelButtonText: @json(__('databackd-data-batches.undo.cancel')),
                customClass: {
                    confirmButton: 'btn btn-danger mr-3',
                    cancelButton: 'btn btn-secondary'
                },
                showClass: {
                    popup: 'swal2-noanimation',
                    backdrop: 'swal2-noanimation'
                },
                buttonsStyling: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.easyAjax({
                    type: 'POST',
                    url: @json(route('databackd.data.batches.undo', ':id')).replace(':id', id),
                    blockUI: true,
                    data: {'_token': @json(csrf_token())},
                    success: function (response) {
                        if (response.status === 'success') {
                            @if (!empty($redirect))
                                window.location.href = @json($redirect);
                            @else
                                window.location.reload();
                            @endif
                        }
                    }
                });
            });
        });
    });
</script>
