@extends('layouts.app')

@php
    $L = 'databackd-gaps-bank-import.';
@endphp

@section('content')
    <div class="content-wrapper">
        @include('databackd.gaps.bank-import._nav', ['active' => 'rules'])

        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
            <p class="text-dark-grey f-14 mb-2 mb-md-0" style="max-width: 760px">@lang($L . 'rules.subtitle')</p>
            <div class="d-flex align-items-center">
                @if ($rules->count() > 8)
                    <div class="input-group bg-grey rounded mr-2" style="width: 240px">
                        <div class="input-group-prepend">
                            <span class="input-group-text border-0 bg-additional-grey"><i class="fa fa-search f-13 text-dark-grey"></i></span>
                        </div>
                        <input type="search" class="form-control f-14 p-1 border-additional-grey" id="rule-search" placeholder="{{ __($L . 'rules.search') }}" aria-label="{{ __($L . 'rules.search') }}">
                    </div>
                @endif
                <x-forms.button-primary id="add-rule" icon="plus">@lang($L . 'rules.add')</x-forms.button-primary>
            </div>
        </div>

        @if ($rules->isEmpty())
            <div class="d-flex flex-column w-tables rounded mt-1 bg-white py-5">
                <x-cards.no-record icon="random" :message="__($L . 'rules.empty_title')"/>
                <p class="text-center text-lightest f-13 mt-2 mb-3">@lang($L . 'rules.empty_text')</p>
                <div class="text-center">
                    <x-forms.link-secondary :link="route('databackd.gaps.bank-import.index')" icon="upload">@lang($L . 'statements.import_first')</x-forms.link-secondary>
                </div>
            </div>
        @else
            <x-cards.data padding="false">
                <div class="table-responsive">
                    <table class="table table-hover bkim-rule-table mb-0" id="rules-table">
                        <thead>
                            <tr>
                                <th class="pl-4">@lang($L . 'rules.col_pattern')</th>
                                <th>@lang($L . 'rules.col_match')</th>
                                <th>@lang($L . 'rules.col_direction')</th>
                                <th>@lang($L . 'rules.col_account')</th>
                                <th>@lang($L . 'rules.col_entity')</th>
                                <th>@lang($L . 'rules.col_name')</th>
                                <th class="pr-4 text-right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rules as $rule)
                                <tr class="bkim-rule-row" data-id="{{ $rule->id }}">
                                    <td class="pl-4 bkim-rule-pattern">{{ $rule->pattern }}</td>
                                    <td><span class="badge badge-light">@lang($L . 'match_types.' . $rule->match_type)</span></td>
                                    <td>
                                        @if ($rule->direction === 'credit')
                                            <span class="badge badge-success">@lang($L . 'directions.credit')</span>
                                        @elseif ($rule->direction === 'debit')
                                            <span class="badge badge-danger">@lang($L . 'directions.debit')</span>
                                        @else
                                            <span class="badge badge-secondary">@lang($L . 'directions.any')</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($rule->account)
                                            <span class="f-w-500">{{ $rule->account->code }}</span> {{ $rule->account->name }}
                                            @if (!$rule->account->is_active)
                                                <span class="badge badge-warning ml-1" title="{{ __($L . 'rules.inactive_account_help') }}">@lang($L . 'rules.inactive_account')</span>
                                            @endif
                                        @else
                                            <span class="text-lightest">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $rule->entity_id ? ($entities[$rule->entity_id]->name ?? '—') : __($L . 'rules.all_entities') }}</td>
                                    <td class="text-dark-grey">{{ $rule->counterparty_name ?: '—' }}</td>
                                    <td class="pr-4 text-right text-nowrap bkim-rule-actions">
                                        <button type="button" class="btn btn-sm btn-outline-secondary edit-rule" data-id="{{ $rule->id }}" title="{{ __($L . 'rules.edit') }}" aria-label="{{ __($L . 'rules.edit') }}"><i class="fa fa-edit" aria-hidden="true"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-danger delete-rule ml-1" data-id="{{ $rule->id }}" data-pattern="{{ $rule->pattern }}" title="{{ __($L . 'rules.delete') }}" aria-label="{{ __($L . 'rules.delete') }}"><i class="fa fa-trash" aria-hidden="true"></i></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cards.data>
        @endif

        <p class="text-lightest f-12 mt-3">@lang($L . 'rules.help')</p>
    </div>
@endsection

@push('scripts')
    <script>
        $('#add-rule').on('click', function () {
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, "{{ route('databackd.gaps.bank-import.rules.create') }}");
        });

        $('body').on('click', '.edit-rule', function () {
            var url = "{{ route('databackd.gaps.bank-import.rules.edit', ':id') }}".replace(':id', $(this).data('id'));
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, url);
        });

        $('body').on('click', '.delete-rule', function () {
            var id = $(this).data('id');

            Swal.fire({
                title: @json(__($L . 'rules.delete_title')),
                text: '„' + $(this).data('pattern') + '“ – ' + @json(__($L . 'rules.delete_text')),
                icon: 'warning',
                showCancelButton: true,
                focusConfirm: false,
                confirmButtonText: @json(__($L . 'rules.delete_confirm')),
                cancelButtonText: @json(__($L . 'rules.cancel')),
                customClass: {confirmButton: 'btn btn-primary mr-3', cancelButton: 'btn btn-secondary'},
                showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                buttonsStyling: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.easyAjax({
                    type: 'POST',
                    url: "{{ route('databackd.gaps.bank-import.rules.destroy', ':id') }}".replace(':id', id),
                    blockUI: true,
                    data: {'_token': '{{ csrf_token() }}', '_method': 'DELETE'},
                    success: function (response) {
                        if (response.status === 'success') {
                            window.location.reload();
                        }
                    }
                });
            });
        });

        $('#rule-search').on('keyup', function () {
            var term = $(this).val().toLowerCase();

            $('#rules-table tbody tr').each(function () {
                $(this).toggle($(this).text().toLowerCase().indexOf(term) !== -1);
            });
        });
    </script>
@endpush
