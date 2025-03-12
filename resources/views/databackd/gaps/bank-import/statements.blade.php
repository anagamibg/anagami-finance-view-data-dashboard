@extends('layouts.app')

@php
    $L = 'databackd-gaps-bank-import.';
    $money = fn($v) => number_format((float)$v, 2, ',', ' ');
    $date = fn($d) => $d ? \Carbon\Carbon::parse($d)->format(company()->date_format) : '—';
@endphp

@section('content')
    <div class="content-wrapper">
        @include('databackd.gaps.bank-import._nav', ['active' => 'statements'])

        <p class="text-dark-grey f-14 mb-3">@lang($L . 'statements.subtitle')</p>

        @if ($allCount > 0 && (count($accounts) > 1 || $entities->count() > 1 || $filtered))
            <form method="GET" action="{{ route('databackd.gaps.bank-import.statements') }}" class="bkim-st-filters mb-3" id="bkim-st-filter-form">
                <div>
                    <label for="bkim-st-entity" class="f-12 text-dark-grey">@lang($L . 'statements.col_entity')</label>
                    <select name="entity_id" id="bkim-st-entity" class="form-control height-35 f-14">
                        <option value="">@lang($L . 'rules.all_entities')</option>
                        @foreach ($entities as $entity)
                            <option value="{{ $entity->id }}" @selected($filterEntity === (int)$entity->id)>{{ $entity->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bkim-st-account" class="f-12 text-dark-grey">@lang($L . 'statements.col_account')</label>
                    <select name="account" id="bkim-st-account" class="form-control height-35 f-14">
                        <option value="">@lang($L . 'statements.all_accounts')</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account }}" @selected($filterAccount === $account)>{{ $account }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <noscript><button type="submit" class="btn btn-secondary height-35 f-14">@lang($L . 'statements.filter_apply')</button></noscript>
                    @if ($filtered)
                        <a href="{{ route('databackd.gaps.bank-import.statements') }}" class="btn btn-outline-secondary rounded f-14 height-35 d-inline-flex align-items-center">
                            <i class="fa fa-times mr-1"></i>@lang($L . 'statements.filter_reset')
                        </a>
                    @endif
                </div>
            </form>
        @endif

        @if ($statements->isEmpty() && $filtered)
            <x-cards.data>
                <x-dbk.empty icon="funnel" :title="__($L . 'statements.empty_filtered')">
                    <a href="{{ route('databackd.gaps.bank-import.statements') }}" class="btn btn-secondary btn-sm">@lang($L . 'statements.filter_reset')</a>
                </x-dbk.empty>
            </x-cards.data>
        @elseif ($statements->isEmpty())
            <div class="d-flex flex-column w-tables rounded mt-1 bg-white py-5">
                <x-cards.no-record icon="university" :message="__($L . 'statements.empty_title')"/>
                <p class="text-center text-lightest f-13 mt-2 mb-3">@lang($L . 'statements.empty_text')</p>
                <div class="text-center">
                    <x-forms.link-primary :link="route('databackd.gaps.bank-import.index')" icon="upload">@lang($L . 'statements.import_first')</x-forms.link-primary>
                </div>
            </div>
        @else
            <x-cards.data padding="false">
                <div class="table-responsive">
                    <table class="table table-hover bkim-st-table mb-0">
                        <thead>
                            <tr>
                                <th class="pl-4">@lang($L . 'statements.col_account')</th>
                                <th>@lang($L . 'statements.col_period')</th>
                                <th>@lang($L . 'statements.col_entity')</th>
                                <th class="num">@lang($L . 'statements.col_opening')</th>
                                <th class="num">@lang($L . 'statements.col_closing')</th>
                                <th class="num">@lang($L . 'statements.col_transactions')</th>
                                <th>@lang($L . 'statements.col_imported')</th>
                                <th class="pr-4 text-right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($statements as $statement)
                                @php
                                    $batch = $statement->importBatch;
                                    $undone = ($batch->status ?? null) === 'undone';
                                @endphp
                                <tr class="@if ($undone) is-undone @endif">
                                    <td class="pl-4">
                                        <div class="f-w-500">{{ $statement->account_iban ?: '—' }}</div>
                                        <div class="f-12 text-lightest">
                                            {{ $statement->currency }}
                                            @if ($statement->statement_no) · {{ $statement->statement_no }} @endif
                                            <span class="badge badge-light ml-1">{{ __($L . 'formats.' . $statement->format) }}</span>
                                        </div>
                                    </td>
                                    <td class="text-nowrap">{{ $date($statement->date_from) }} – {{ $date($statement->date_to) }}</td>
                                    <td>{{ $statement->entity->name ?? '—' }}</td>
                                    <td class="num">{{ $statement->opening_balance !== null ? $money($statement->opening_balance) : '—' }}</td>
                                    <td class="num">{{ $statement->closing_balance !== null ? $money($statement->closing_balance) : '—' }}</td>
                                    <td class="num">{{ $statement->transactions_count }}</td>
                                    <td class="text-nowrap" @if ($statement->file_name) title="{{ $statement->file_name }}" @endif>
                                        {{ $batch?->created_at ? $batch->created_at->timezone(company()->timezone)->format(company()->date_format . ' ' . company()->time_format) : '—' }}
                                        <div>
                                            @if ($undone)
                                                <span class="badge badge-secondary">@lang($L . 'statements.undone')</span>
                                            @elseif (($batch->rows_failed ?? 0) > 0)
                                                <span class="badge badge-warning">@lang($L . 'statements.with_errors')</span>
                                            @else
                                                <span class="badge badge-success">@lang($L . 'statements.imported')</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="pr-4 text-right text-nowrap">
                                        @if ($statement->import_batch_id)
                                            <a href="{{ route('databackd.gaps.bank-import.result', $statement->import_batch_id) }}" class="btn btn-sm btn-outline-secondary" title="{{ __($L . 'statements.open') }}" aria-label="{{ __($L . 'statements.open') }}"><i class="fa fa-eye" aria-hidden="true"></i></a>
                                            <button type="button" class="btn btn-sm btn-outline-danger ml-1 bkim-undo" data-batch="{{ $statement->import_batch_id }}"
                                                    data-undone="{{ $undone ? 1 : 0 }}" data-label="{{ ($statement->account_iban ?: '—') . ' · ' . $date($statement->date_from) . ' – ' . $date($statement->date_to) }}"
                                                    data-count="{{ (int)$statement->transactions_count }}"
                                                    title="{{ $undone ? __($L . 'statements.remove') : __($L . 'statements.undo') }}" aria-label="{{ $undone ? __($L . 'statements.remove') : __($L . 'statements.undo') }}">
                                                <i class="fa fa-{{ $undone ? 'trash' : 'undo' }}"></i>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cards.data>

            @if ($total > $limit)
                <p class="text-lightest f-12 mt-2">@lang($L . 'statements.limited', ['shown' => $limit, 'total' => $total])</p>
            @endif
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $('#bkim-st-entity, #bkim-st-account').on('change', function () {
            $('#bkim-st-filter-form').submit();
        });

        $('body').on('click', '.bkim-undo', function () {
            var id = $(this).data('batch');
            var undone = $(this).data('undone') === 1;
            var details = {!! json_encode(__($L . 'statements.undo_details', ['label' => ':label', 'count' => ':count']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}
                .replace(':label', $(this).data('label')).replace(':count', $(this).data('count'));

            Swal.fire({
                title: undone ? @json(__($L . 'statements.remove_title')) : @json(__($L . 'statements.undo_title')),
                text: undone ? @json(__($L . 'statements.remove_text')) : details + ' ' + @json(__($L . 'statements.undo_text')),
                icon: 'warning',
                showCancelButton: true,
                focusConfirm: false,
                confirmButtonText: undone ? @json(__($L . 'statements.remove_confirm')) : @json(__($L . 'statements.undo_confirm')),
                cancelButtonText: @json(__($L . 'statements.undo_cancel')),
                customClass: {confirmButton: 'btn btn-primary mr-3', cancelButton: 'btn btn-secondary'},
                showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                buttonsStyling: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.easyAjax({
                    type: 'POST',
                    url: "{{ route('databackd.gaps.bank-import.statements.undo', ':id') }}".replace(':id', id),
                    blockUI: true,
                    data: {'_token': '{{ csrf_token() }}'},
                    success: function (response) {
                        if (response.status === 'success') {
                            window.location.reload();
                        }
                    }
                });
            });
        });
    </script>
@endpush
