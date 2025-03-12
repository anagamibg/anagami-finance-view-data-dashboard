@extends('layouts.app')

@php
    $L = 'databackd-gaps-bank-import.';
    $money = fn($v) => number_format((float)$v, 2, ',', ' ');
    $date = fn($d) => $d ? \Carbon\Carbon::parse($d)->format(company()->date_format) : '—';
    $baseCurrency = company()->currency->currency_code ?? 'EUR';
    $statusColors = ['completed' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'undone' => 'secondary', 'pending' => 'info', 'processing' => 'info'];
    $undone = $batch->status === 'undone';
@endphp

@section('content')
    <div class="content-wrapper">
        @include('databackd.gaps.bank-import._nav', ['active' => 'import'])
        @include('databackd.gaps.bank-import._steps', ['step' => 4])

        <div class="row">
            <div class="col-md-3 col-6 mb-3">
                <div class="bkim-result-kpi">
                    <i class="fa fa-check-circle f-30 text-success mr-3"></i>
                    <div>
                        <div class="bkim-result-value bkim-in">{{ number_format($batch->rows_imported, 0, ',', ' ') }}</div>
                        <div class="f-13 text-dark-grey">@lang($L . 'result.imported')</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="bkim-result-kpi">
                    <i class="fa fa-forward f-30 {{ $skipped ? 'text-warning' : 'text-lightest' }} mr-3"></i>
                    <div>
                        <div class="bkim-result-value">{{ number_format($skipped, 0, ',', ' ') }}</div>
                        <div class="f-13 text-dark-grey">@lang($L . 'result.skipped')</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="bkim-result-kpi">
                    <i class="fa fa-exclamation-circle f-30 {{ $batch->rows_failed ? 'text-danger' : 'text-lightest' }} mr-3"></i>
                    <div>
                        <div class="bkim-result-value {{ $batch->rows_failed ? 'bkim-out' : '' }}">{{ number_format($batch->rows_failed, 0, ',', ' ') }}</div>
                        <div class="f-13 text-dark-grey">@lang($L . 'result.failed')</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="bkim-result-kpi">
                    <i class="fa fa-balance-scale f-30 text-primary mr-3"></i>
                    <div>
                        <div class="bkim-result-value">{{ number_format($balanceEntries, 0, ',', ' ') }}</div>
                        <div class="f-13 text-dark-grey">@lang($L . 'result.balances')</div>
                    </div>
                </div>
            </div>
        </div>

        <x-cards.data :title="__($L . 'result.title')" class="mb-4">
            @if ($undone)
                <div class="alert alert-secondary">{{ __($L . 'result.undone') }}</div>
            @elseif ($batch->status === 'completed')
                <div class="alert alert-success">{{ __($L . 'result.completed_ok') }}</div>
            @elseif ($batch->status === 'partial')
                <div class="alert alert-warning">{{ __($L . 'result.completed_partial') }}</div>
            @else
                <div class="alert alert-danger">{{ __($L . 'result.completed_failed') }}</div>
            @endif

            <div class="row f-14 mb-3">
                <div class="col-md-4 mb-2">
                    <span class="text-dark-grey">@lang($L . 'result.status'):</span>
                    <span class="badge badge-{{ $statusColors[$batch->status] ?? 'secondary' }}">{{ __('databackd-data-common.statuses.' . $batch->status) }}</span>
                </div>
                <div class="col-md-4 mb-2">
                    <span class="text-dark-grey">@lang($L . 'result.file'):</span>
                    <span class="f-w-500">{{ $batch->file_name ?: '—' }}</span>
                    <span class="badge badge-light">{{ __($L . 'formats.' . $batch->source) }}</span>
                </div>
                <div class="col-md-4 mb-2">
                    <span class="text-dark-grey">@lang($L . 'result.created_at'):</span>
                    {{ $batch->created_at ? $batch->created_at->timezone(company()->timezone)->format(company()->date_format . ' ' . company()->time_format) : '' }}
                </div>
                <div class="col-md-4 mb-2">
                    <span class="text-dark-grey">@lang($L . 'result.receipts'):</span>
                    <span class="f-w-500 bkim-in">+{{ $money($receipts) }} {{ $baseCurrency }}</span>
                    <span class="text-lightest">({{ $receiptsCount }})</span>
                </div>
                <div class="col-md-4 mb-2">
                    <span class="text-dark-grey">@lang($L . 'result.payments'):</span>
                    <span class="f-w-500 bkim-out">−{{ $money($payments) }} {{ $baseCurrency }}</span>
                    <span class="text-lightest">({{ $paymentsCount }})</span>
                </div>
            </div>

            @if ($statements->isNotEmpty())
                <h6 class="f-15 f-w-500 mb-2">@lang($L . 'result.statements_title')</h6>
                <div class="table-responsive">
                    <table class="table table-sm bkim-res-table mb-3">
                        <thead>
                            <tr>
                                <th>@lang($L . 'statements.col_account')</th>
                                <th>@lang($L . 'statements.col_period')</th>
                                <th>@lang($L . 'statements.col_entity')</th>
                                <th class="num">@lang($L . 'statements.col_opening')</th>
                                <th class="num">@lang($L . 'statements.col_closing')</th>
                                <th class="num">@lang($L . 'statements.col_transactions')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($statements as $statement)
                                <tr>
                                    <td>
                                        <div class="f-w-500">{{ $statement->account_iban ?: '—' }}</div>
                                        <div class="f-12 text-lightest">{{ $statement->currency }} @if ($statement->statement_no) · {{ $statement->statement_no }} @endif</div>
                                    </td>
                                    <td class="text-nowrap">{{ $date($statement->date_from) }} – {{ $date($statement->date_to) }}</td>
                                    <td>{{ $statement->entity->name ?? '—' }}</td>
                                    <td class="num">{{ $statement->opening_balance !== null ? $money($statement->opening_balance) : '—' }}</td>
                                    <td class="num">{{ $statement->closing_balance !== null ? $money($statement->closing_balance) : '—' }}</td>
                                    <td class="num">{{ $statement->transactions_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($warnings->isNotEmpty())
                <h6 class="f-15 f-w-500 mb-2">@lang($L . 'result.warnings_title')</h6>
                <ul class="f-13 text-dark-grey pl-3 mb-0">
                    @foreach ($warnings as $warning)
                        <li>
                            @if (!empty($warning['row']))<span class="text-lightest">@lang($L . 'result.row', ['row' => $warning['row']]):</span>@endif
                            {{ implode(' ', (array)($warning['messages'] ?? [])) }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-cards.data>

        <div class="mb-4">
            <x-forms.link-primary :link="route('databackd.gaps.bank-import.index')" icon="upload" class="mr-2">
                {{ __($L . 'result.new_import') }}
            </x-forms.link-primary>
            @if ($entriesUrl && !$undone)
                <x-forms.link-secondary :link="$entriesUrl" icon="table" class="mr-2">{{ __($L . 'result.view_entries') }}</x-forms.link-secondary>
            @endif
            <x-forms.link-secondary :link="route('databackd.gaps.bank-import.statements')" icon="university" class="mr-2">
                {{ __($L . 'result.statements_list') }}
            </x-forms.link-secondary>
            <x-forms.link-secondary :link="route('databackd.gaps.bank-import.rules.index')" icon="random" class="mr-2">
                {{ __($L . 'result.rules') }}
            </x-forms.link-secondary>
            @if (!$undone)
                <x-forms.button-secondary id="bank-import-undo" icon="undo" class="text-danger">{{ __($L . 'statements.undo') }}</x-forms.button-secondary>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    @if (!$undone)
        <script>
            $('#bank-import-undo').on('click', function () {
                Swal.fire({
                    title: @json(__($L . 'statements.undo_title')),
                    text: {!! json_encode(__($L . 'statements.undo_details', ['label' => $batch->file_name ?: ('#' . $batch->id), 'count' => (int)$batch->rows_imported]) . ' ' . __($L . 'statements.undo_text'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!},
                    icon: 'warning',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: @json(__($L . 'statements.undo_confirm')),
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
                        url: "{{ route('databackd.gaps.bank-import.statements.undo', $batch->id) }}",
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
    @endif
@endpush
