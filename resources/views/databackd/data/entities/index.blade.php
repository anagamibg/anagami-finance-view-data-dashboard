@extends('layouts.app')

@section('content')
    <div class="content-wrapper dbk-data-page">
        <x-dbk.page-header :subtitle="__('databackd-data-dimensions.entities_help')" />

        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="__('databackd-data-dimensions.group_total')" :value="$totalEntities" icon="bi-building" />
            <x-dbk.kpi :label="__('databackd-data-dimensions.group_active')" :value="$activeEntities" icon="bi-check-circle" />
            <x-dbk.kpi :label="__('databackd-data-dimensions.group_roots')" :value="$rootCount" icon="bi-diagram-3" />
            <x-dbk.kpi :label="__('databackd-data-dimensions.group_entries')" :value="number_format($totalEntries, 0, ',', ' ')" icon="bi-list-ol" />
        </div>

        @if (count($rows) === 0)
            <div class="dbk-card">
                <x-dbk.empty icon="building" :title="__('databackd-data-dimensions.empty_entities')" :text="__('databackd-data-dimensions.empty_entities_hint')">
                    <x-forms.button-primary class="add-entity" icon="plus">@lang('databackd-data-dimensions.add_entity')</x-forms.button-primary>
                </x-dbk.empty>
            </div>
        @else
            <x-dbk.card :title="__('databackd-data-dimensions.group_scheme')" :subtitle="__('databackd-data-dimensions.group_scheme_hint')" class="mb-4">
                <div class="dbk-scheme-wrap">
                    <ul class="dbk-scheme">
                        @foreach ($tree as $node)
                            @include('databackd.data.entities._node', ['node' => $node])
                        @endforeach
                    </ul>
                </div>
            </x-dbk.card>

            <x-dbk.card :title="__('databackd-data-dimensions.entities_list')" body-class="p-0">
                <x-slot name="actions">
                    <x-forms.button-primary class="add-entity" icon="plus">@lang('databackd-data-dimensions.add_entity')</x-forms.button-primary>
                </x-slot>

                <div class="dbk-table-wrap is-flush">
                    <x-table class="dbk-table dbk-table--compact dbk-ent-table" id="entities-table">
                        <x-slot name="thead">
                            <th>@lang('databackd-data-dimensions.entity_name')</th>
                            <th>@lang('databackd-data-dimensions.eik')</th>
                            <th>@lang('databackd-data-dimensions.country')</th>
                            <th>@lang('databackd-data-dimensions.currency')</th>
                            <th>@lang('databackd-data-dimensions.parent')</th>
                            <th class="num">@lang('databackd-data-dimensions.ownership_pct')</th>
                            <th class="num">@lang('databackd-data-dimensions.entries')</th>
                            <th>@lang('databackd-data-dimensions.is_active')</th>
                            <th class="text-right" style="width: 110px">@lang('databackd-data-dimensions.actions')</th>
                        </x-slot>

                        @foreach ($rows as $row)
                            @php($e = $row['node']['entity'])
                            <tr id="entity-row-{{ $e->id }}">
                                <td class="dbk-ent-name">
                                    <span class="dbk-indent" style="width: {{ $row['depth'] * 22 }}px"></span>
                                    @if ($row['depth'] > 0)
                                        <i class="fa fa-level-up-alt fa-rotate-90 dbk-indent mr-1"></i>
                                    @else
                                        <i class="fa fa-building text-lightest mr-1"></i>
                                    @endif
                                    <span class="@if ($row['depth'] === 0) f-w-500 @endif">{{ $e->name }}</span>
                                </td>
                                <td>{{ $e->eik ?: '—' }}</td>
                                <td>{{ $e->country }}</td>
                                <td>{{ $e->currency }}</td>
                                <td>{{ $e->parent_id ? ($names[$e->parent_id] ?? '—') : '—' }}</td>
                                <td class="num">
                                    @if ($e->parent_id)
                                        {{ rtrim(rtrim(number_format($e->ownership_pct, 2, ',', ''), '0'), ',') }}%
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="num">{{ number_format($row['node']['entries'], 0, ',', ' ') }}</td>
                                <td>
                                    @if ($e->is_active)
                                        <span class="dbk-badge is-good">@lang('databackd-data-dimensions.active')</span>
                                    @else
                                        <span class="dbk-badge">@lang('databackd-data-dimensions.inactive')</span>
                                    @endif
                                </td>
                                <td class="text-right text-nowrap dbk-data-row-actions">
                                    <button type="button" class="btn btn-sm btn-outline-secondary edit-entity"
                                            data-id="{{ $e->id }}" title="@lang('databackd-data-dimensions.edit')"
                                            aria-label="@lang('databackd-data-dimensions.edit') {{ $e->name }}">
                                        <i class="fa fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-entity ml-1"
                                            data-id="{{ $e->id }}" data-name="{{ $e->name }}"
                                            data-entries="{{ $row['node']['entries'] }}" data-children="{{ count($row['node']['children']) }}"
                                            title="@lang('databackd-data-dimensions.delete')"
                                            aria-label="@lang('databackd-data-dimensions.delete') {{ $e->name }}">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                </div>
            </x-dbk.card>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $('body').on('click', '.add-entity', function () {
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, "{{ route('databackd.data.entities.create') }}");
        });

        $('body').on('click', '.edit-entity', function () {
            var url = "{{ route('databackd.data.entities.edit', ':id') }}".replace(':id', $(this).data('id'));
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, url);
        });

        $('body').on('click', '.delete-entity', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var entries = parseInt($(this).data('entries'), 10) || 0;
            var children = parseInt($(this).data('children'), 10) || 0;

            // with entries or subsidiaries it cannot be deleted: explain it instead of asking first and failing after
            if (entries > 0 || children > 0) {
                Swal.fire({
                    icon: 'info',
                    title: @json(__('databackd-data-dimensions.cannot_delete_title')),
                    text: entries > 0
                        ? @json(__('databackd-data-dimensions.entity_has_entries', ['count' => ':count'])).replace(':count', entries)
                        : @json(__('databackd-data-dimensions.entity_has_children', ['count' => ':count'])).replace(':count', children),
                    confirmButtonText: @json(__('databackd-data-dimensions.close')),
                    customClass: {confirmButton: 'btn btn-primary'},
                    buttonsStyling: false
                });
                return;
            }

            Swal.fire({
                title: @json(__('databackd-data-dimensions.confirm_title')),
                text: @json(__('databackd-data-dimensions.delete_entity_confirm', ['name' => ':name'])).replace(':name', name),
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
                    url: "{{ route('databackd.data.entities.destroy', ':id') }}".replace(':id', id),
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
