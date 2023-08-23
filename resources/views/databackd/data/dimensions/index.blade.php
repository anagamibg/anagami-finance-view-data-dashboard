@extends('layouts.app')

@section('content')
    <div class="content-wrapper dbk-data-page">
        <x-dbk.page-header :subtitle="__('databackd-data-dimensions.dimensions_help')" />

        <div class="dbk-card">
            <ul class="nav dbk-tabs dbk-dim-tabs" id="dimension-tabs" role="tablist">
                @foreach (\App\Models\Databackd\FinDimension::TYPES as $type)
                    <li class="nav-item">
                        <a class="nav-link @if ($type === $activeType) active @endif" id="tab-{{ $type }}"
                           data-toggle="tab" data-type="{{ $type }}" href="#pane-{{ $type }}" role="tab"
                           aria-controls="pane-{{ $type }}" aria-selected="{{ $type === $activeType ? 'true' : 'false' }}">
                            @lang('databackd-data-common.dimension_types.' . $type)
                            <span class="dbk-chip">{{ $dimensionsByType[$type]->count() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content dbk-card-body">
                @foreach (\App\Models\Databackd\FinDimension::TYPES as $type)
                    <div class="tab-pane fade @if ($type === $activeType) show active @endif" id="pane-{{ $type }}"
                         role="tabpanel" aria-labelledby="tab-{{ $type }}">

                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                            <div class="mb-2 mb-md-0">
                                <h3 class="dbk-card-title">
                                    @lang('databackd-data-common.dimension_types.' . $type)
                                </h3>
                                <div class="dbk-card-subtitle">
                                    @lang('databackd-data-dimensions.type_hints.' . $type)
                                    &middot; @lang('databackd-data-dimensions.tab_counts', ['count' => number_format($totalsByType[$type], 0, ',', ' ')])
                                </div>
                            </div>
                            <x-forms.button-primary class="add-dimension" data-type="{{ $type }}" icon="plus">
                                @lang('databackd-data-dimensions.add')
                            </x-forms.button-primary>
                        </div>

                        @if ($dimensionsByType[$type]->isEmpty())
                            <x-dbk.empty icon="diagram-3" :title="__('databackd-data-dimensions.empty_dimensions')" :text="__('databackd-data-dimensions.empty_dimensions_hint')" class="dbk-data-empty-box">
                                <x-forms.button-primary class="add-dimension" data-type="{{ $type }}" icon="plus">
                                    @lang('databackd-data-dimensions.add')
                                </x-forms.button-primary>
                            </x-dbk.empty>
                        @else
                            <div class="dbk-table-wrap">
                                <x-table class="dbk-table dbk-table--compact dbk-dim-table" id="table-{{ $type }}">
                                    <x-slot name="thead">
                                        <th>@lang('databackd-data-dimensions.name')</th>
                                        <th>@lang('databackd-data-dimensions.code')</th>
                                        <th class="num">@lang('databackd-data-dimensions.entries_count')</th>
                                        <th class="text-right" style="width: 130px">@lang('databackd-data-dimensions.actions')</th>
                                    </x-slot>

                                    @foreach ($dimensionsByType[$type] as $item)
                                        <tr id="dim-row-{{ $item->id }}">
                                            <td class="f-w-500">{{ $item->name }}</td>
                                            <td>
                                                @if ($item->code)
                                                    <span class="dbk-data-code">{{ $item->code }}</span>
                                                @else
                                                    <span class="dbk-text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="num">{{ number_format($item->entries_count, 0, ',', ' ') }}</td>
                                            <td class="text-right text-nowrap dbk-data-row-actions">
                                                <button type="button" class="btn btn-sm btn-outline-secondary edit-dimension"
                                                        data-id="{{ $item->id }}" title="@lang('databackd-data-dimensions.edit')"
                                                        aria-label="@lang('databackd-data-dimensions.edit') {{ $item->name }}">
                                                    <i class="fa fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger delete-dimension ml-1"
                                                        data-id="{{ $item->id }}" data-name="{{ $item->name }}" data-entries="{{ $item->entries_count }}"
                                                        title="@lang('databackd-data-dimensions.delete')"
                                                        aria-label="@lang('databackd-data-dimensions.delete') {{ $item->name }}">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </x-table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        var dimensionsIndexUrl = "{{ route('databackd.data.dimensions.index') }}";

        // Keep the active tab in ?type= (no reload)
        $('#dimension-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            var type = $(e.target).data('type');
            if (window.history && history.replaceState) {
                history.replaceState(null, '', dimensionsIndexUrl + '?type=' + type);
            }
        });

        $('body').on('click', '.add-dimension', function () {
            var url = "{{ route('databackd.data.dimensions.create') }}?type=" + $(this).data('type');
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, url);
        });

        $('body').on('click', '.edit-dimension', function () {
            var url = "{{ route('databackd.data.dimensions.edit', ':id') }}".replace(':id', $(this).data('id'));
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, url);
        });

        $('body').on('click', '.delete-dimension', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var entries = parseInt($(this).data('entries'), 10) || 0;

            // used by entries: say why it cannot be deleted instead of asking first and failing after
            if (entries > 0) {
                Swal.fire({
                    icon: 'info',
                    title: @json(__('databackd-data-dimensions.cannot_delete_title')),
                    text: @json(__('databackd-data-dimensions.dimension_in_use', ['count' => ':count'])).replace(':count', entries),
                    confirmButtonText: @json(__('databackd-data-dimensions.close')),
                    customClass: {confirmButton: 'btn btn-primary'},
                    buttonsStyling: false
                });
                return;
            }

            Swal.fire({
                title: @json(__('databackd-data-dimensions.confirm_title')),
                text: @json(__('databackd-data-dimensions.delete_dimension_confirm', ['name' => ':name'])).replace(':name', name),
                icon: 'warning',
                showCancelButton: true,
                focusConfirm: false,
                confirmButtonText: @json(__('databackd-data-dimensions.confirm_delete')),
                cancelButtonText: @json(__('databackd-data-dimensions.cancel')),
                customClass: {
                    confirmButton: 'btn btn-primary mr-3',
                    cancelButton: 'btn btn-secondary'
                },
                showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                buttonsStyling: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.easyAjax({
                    type: 'POST',
                    url: "{{ route('databackd.data.dimensions.destroy', ':id') }}".replace(':id', id),
                    blockUI: true,
                    data: {'_token': '{{ csrf_token() }}', '_method': 'DELETE'},
                    success: function (response) {
                        if (response.status == 'success') {
                            window.location.reload();
                        }
                    }
                });
            });
        });
    </script>
@endpush
