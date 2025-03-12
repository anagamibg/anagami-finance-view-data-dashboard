@extends('layouts.app')

@php
    $L = 'databackd-gaps-bank-import.';
    $money = fn($v) => number_format((float)$v, 2, ',', ' ');
    $date = fn($d) => $d ? \Carbon\Carbon::parse($d)->format(company()->date_format) : '—';
    $accountLabel = fn($a) => $a['code'] . ' — ' . $a['name'];
@endphp

@section('content')
    <div class="content-wrapper">
        @include('databackd.gaps.bank-import._nav', ['active' => 'import'])
        @include('databackd.gaps.bank-import._steps', ['step' => 2])

        @if (empty($meta))
            <x-cards.data>
                <div class="text-center py-5">
                    <i class="fa fa-file-upload f-30 text-lightest mb-3"></i>
                    <h5>{{ $fileError ? __($L . 'review.file_error_title') : __($L . 'review.no_file_title') }}</h5>
                    <p class="text-dark-grey">{{ $fileError ?: __($L . 'review.no_file_text') }}</p>
                    <x-forms.link-primary :link="route('databackd.gaps.bank-import.index')" icon="arrow-left">
                        {{ __($L . 'review.no_file_button') }}
                    </x-forms.link-primary>
                </div>
            </x-cards.data>
        @else
            <x-form id="bank-import-run-form">
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="decisions" id="decisions" value="">

                {{-- file and totals --}}
                <x-cards.data :title="__($L . 'review.file_title')">
                    <p class="text-dark-grey f-14 mb-3">
                        <i class="fa fa-file-alt mr-1"></i>
                        <span class="f-w-500">{{ $meta['name'] }}</span>
                        <span class="badge badge-light ml-2">{{ __($L . 'formats.' . $parsed['format']) }}</span>
                        <span class="ml-2">{{ __($L . 'review.file_info', ['statements' => count($parsed['statements']), 'transactions' => collect($parsed['statements'])->sum(fn($s) => count($s['transactions']))]) }}</span>
                    </p>

                    @foreach ($parsed['warnings'] as $warning)
                        <div class="alert alert-warning f-13 py-2 mb-2"><i class="fa fa-exclamation-triangle mr-1"></i>{{ $warning }}</div>
                    @endforeach

                    <div class="row">
                        <div class="col-6 col-md-3 mb-2"><div class="bkim-stat">
                            <div class="bkim-stat-label">@lang($L . 'review.stat_transactions')</div>
                            <div class="bkim-stat-value" id="bkim-stat-selected">{{ $review['transactions'] }}</div>
                        </div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="bkim-stat">
                            <div class="bkim-stat-label">@lang($L . 'review.stat_receipts')</div>
                            <div class="bkim-stat-value bkim-in">{{ $money($review['receipts']) }}</div>
                        </div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="bkim-stat">
                            <div class="bkim-stat-label">@lang($L . 'review.stat_payments')</div>
                            <div class="bkim-stat-value bkim-out">{{ $money($review['payments']) }}</div>
                        </div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="bkim-stat">
                            <div class="bkim-stat-label">@lang($L . 'review.stat_unmatched')</div>
                            <div class="bkim-stat-value" id="bkim-stat-unmatched">{{ $review['unmatched'] }}</div>
                        </div></div>
                    </div>
                </x-cards.data>

                {{-- settings --}}
                <x-cards.data :title="__($L . 'review.settings_title')" class="mt-3">
                    <div class="row">
                        <div class="col-lg-3 col-md-6">
                            <label class="f-14 text-dark-grey mb-1" for="entity_id">@lang($L . 'review.entity')</label>
                            <select name="entity_id" id="entity_id" class="form-control form-control-sm">
                                @foreach ($entities as $entity)
                                    <option value="{{ $entity->id }}" @selected($entityId == $entity->id)>{{ $entity->name }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">@lang($L . 'review.entity_help')</small>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="f-14 text-dark-grey mb-1" for="default_credit">@lang($L . 'review.default_credit')</label>
                            <select id="default_credit" class="form-control form-control-sm">
                                <option value="0">@lang($L . 'review.skip')</option>
                                @foreach ($creditAccounts as $account)
                                    <option value="{{ $account['id'] }}" @selected(($review['defaults']['credit'] ?? null) == $account['id'])>{{ $accountLabel($account) }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">@lang($L . 'review.default_help')</small>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="f-14 text-dark-grey mb-1" for="default_debit">@lang($L . 'review.default_debit')</label>
                            <select id="default_debit" class="form-control form-control-sm">
                                <option value="0">@lang($L . 'review.skip')</option>
                                @foreach ($debitAccounts as $account)
                                    <option value="{{ $account['id'] }}" @selected(($review['defaults']['debit'] ?? null) == $account['id'])>{{ $accountLabel($account) }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">@lang($L . 'review.default_help')</small>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="f-14 text-dark-grey mb-1" for="cash_account_id">@lang($L . 'review.cash_account')</label>
                            <select name="cash_account_id" id="cash_account_id" class="form-control form-control-sm">
                                @foreach ($cashAccounts as $account)
                                    <option value="{{ $account['id'] }}" @selected(($review['defaults']['cash'] ?? null) == $account['id'])>{{ $accountLabel($account) }}</option>
                                @endforeach
                            </select>
                            <div class="custom-control custom-switch mt-2">
                                <input type="checkbox" class="custom-control-input" name="record_balances" id="record_balances" value="1" checked>
                                <label class="custom-control-label f-13 text-dark-grey cursor-pointer" for="record_balances">@lang($L . 'review.record_balances')</label>
                            </div>
                        </div>
                    </div>
                    <p class="bkim-note mt-3 mb-0"><i class="fa fa-info-circle mr-1"></i>@lang($L . 'review.settings_note')</p>
                </x-cards.data>

                {{-- filter, search and bulk account of the transactions (applies to all statements of the file) --}}
                @php
                    $hasRows = collect($review['statements'])->contains(fn($st) => $st['importable'] && $st['rows']);
                @endphp
                @if ($hasRows)
                    <x-cards.data class="mt-3">
                        <div class="bkim-toolbar">
                            <div>
                                <span class="d-block f-12 text-dark-grey mb-1" id="bkim-filter-label">@lang($L . 'review.filter_label')</span>
                                <div class="dbk-segmented" role="group" aria-labelledby="bkim-filter-label">
                                    @foreach (['all', 'unmatched', 'skipped', 'duplicate'] as $filter)
                                        <button type="button" class="bkim-filter @if ($filter === 'all') is-active @endif" data-filter="{{ $filter }}"
                                                aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">
                                            @lang($L . 'review.filter_' . $filter)<span class="bkim-count" data-count="{{ $filter }}"></span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            <div class="bkim-toolbar-search">
                                <label for="bkim-search" class="d-block f-12 text-dark-grey mb-1">@lang($L . 'review.search')</label>
                                <input type="search" id="bkim-search" class="form-control form-control-sm" autocomplete="off"
                                       placeholder="{{ __($L . 'review.search_placeholder') }}">
                            </div>
                            <div class="bkim-toolbar-bulk">
                                <label for="bkim-bulk-account" class="d-block f-12 text-dark-grey mb-0 w-100">@lang($L . 'review.bulk_label')</label>
                                <select id="bkim-bulk-account" class="form-control form-control-sm">
                                    <option value="">@lang($L . 'review.bulk_choose')</option>
                                    <option value="0" data-dir="any">@lang($L . 'review.skip')</option>
                                    <optgroup label="{{ __($L . 'review.bulk_credit') }}">
                                        @foreach ($creditAccounts as $account)
                                            <option value="{{ $account['id'] }}" data-dir="credit">{{ $accountLabel($account) }}</option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="{{ __($L . 'review.bulk_debit') }}">
                                        @foreach ($debitAccounts as $account)
                                            <option value="{{ $account['id'] }}" data-dir="debit">{{ $accountLabel($account) }}</option>
                                        @endforeach
                                    </optgroup>
                                </select>
                                <button type="button" class="btn btn-secondary btn-sm height-35" id="bkim-bulk-apply">@lang($L . 'review.bulk_apply')</button>
                            </div>
                        </div>
                        <p class="bkim-note mt-2 mb-0" id="bkim-bulk-result" aria-live="polite"></p>
                    </x-cards.data>
                @endif

                {{-- statements --}}
                @php $rendered = 0; $truncated = false; @endphp
                @foreach ($review['statements'] as $position => $st)
                    @php
                        $h = $st['header'];
                        $s = $st['summary'];
                        $dup = $st['duplicate'];
                        $balances = $st['balances'];
                    @endphp

                    <x-cards.data class="mt-3" padding="false">
                        <div class="px-4 pt-4 pb-3">
                            <div class="d-flex justify-content-between flex-wrap align-items-start">
                                <div class="mb-2">
                                    <div class="f-16 f-w-500 text-dark">
                                        <i class="fa fa-university text-lightest mr-1"></i>{{ $h['account_iban'] ?: __($L . 'review.no_iban') }}
                                        <span class="badge badge-light ml-1">{{ $h['currency'] }}</span>
                                    </div>
                                    <div class="f-13 text-lightest">
                                        @if ($h['statement_no'])
                                            {{ __($L . 'review.statement_no', ['no' => $h['statement_no']]) }} ·
                                        @endif
                                        {{ $date($h['date_from']) }} – {{ $date($h['date_to']) }}
                                        · {{ __($L . 'review.transactions_count', ['count' => $s['count']]) }}
                                    </div>
                                </div>
                                <div class="mb-2">
                                    @if ($s['balanced'] === true)
                                        <span class="bkim-check-ok f-13"><i class="fa fa-check-circle mr-1"></i>@lang($L . 'review.check_ok')</span>
                                    @elseif ($s['balanced'] === false)
                                        <span class="bkim-check-bad f-13"><i class="fa fa-exclamation-circle mr-1"></i>@lang($L . 'review.check_bad', ['difference' => $money($s['difference'])])</span>
                                    @else
                                        <span class="text-lightest f-13">@lang($L . 'review.check_unknown')</span>
                                    @endif
                                </div>
                            </div>

                            @if (!empty($h['account_iban']) && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $h['account_iban']) && !\App\Services\Databackd\Gaps\BankImport\Iban::isValid($h['account_iban']))
                                <div class="alert alert-warning f-13 py-2 mb-2"><i class="fa fa-exclamation-triangle mr-1"></i>{{ __($L . 'review.iban_invalid', ['iban' => $h['account_iban']]) }}</div>
                            @endif

                            <div class="row">
                                <div class="col-6 col-md-2 mb-2"><div class="bkim-stat">
                                    <div class="bkim-stat-label">@lang($L . 'review.opening')</div>
                                    <div class="bkim-stat-value">{{ $s['opening'] !== null ? $money($s['opening']) : '—' }}</div>
                                </div></div>
                                <div class="col-6 col-md-2 mb-2"><div class="bkim-stat">
                                    <div class="bkim-stat-label">@lang($L . 'review.receipts')</div>
                                    <div class="bkim-stat-value bkim-in">+{{ $money($s['receipts']) }}</div>
                                </div></div>
                                <div class="col-6 col-md-2 mb-2"><div class="bkim-stat">
                                    <div class="bkim-stat-label">@lang($L . 'review.payments')</div>
                                    <div class="bkim-stat-value bkim-out">−{{ $money($s['payments']) }}</div>
                                </div></div>
                                <div class="col-6 col-md-2 mb-2"><div class="bkim-stat">
                                    <div class="bkim-stat-label">@lang($L . 'review.net')</div>
                                    <div class="bkim-stat-value">{{ $money($s['net']) }}</div>
                                </div></div>
                                <div class="col-6 col-md-2 mb-2"><div class="bkim-stat">
                                    <div class="bkim-stat-label">@lang($L . 'review.expected_closing')</div>
                                    <div class="bkim-stat-value">{{ $s['expected_closing'] !== null ? $money($s['expected_closing']) : '—' }}</div>
                                </div></div>
                                <div class="col-6 col-md-2 mb-2"><div class="bkim-stat">
                                    <div class="bkim-stat-label">@lang($L . 'review.closing')</div>
                                    <div class="bkim-stat-value">{{ $s['closing'] !== null ? $money($s['closing']) : '—' }}</div>
                                </div></div>
                            </div>

                            @if ($dup && $dup['level'] !== 'overlap')
                                <div class="alert alert-danger f-13 mt-2 mb-0">
                                    <i class="fa fa-ban mr-1"></i>
                                    {{ __($L . 'review.duplicate_' . $dup['level'], ['date' => $date($dup['date']), 'file' => $dup['file_name'] ?: '—', 'id' => $dup['batch_id'] ?: '—']) }}
                                    @if ($dup['batch_id'])
                                        <a href="{{ route('databackd.gaps.bank-import.result', $dup['batch_id']) }}">@lang($L . 'review.duplicate_open', ['id' => $dup['batch_id']])</a>
                                    @endif
                                </div>
                            @else
                                @if ($dup)
                                    <div class="alert alert-warning f-13 mt-2 mb-2">
                                        <i class="fa fa-exclamation-triangle mr-1"></i>
                                        {{ __($L . 'review.duplicate_overlap', ['date' => $date($dup['date']), 'file' => $dup['file_name'] ?: '—']) }}
                                        @if ($s['duplicates'] > 0)
                                            @lang($L . 'review.duplicate_rows', ['count' => $s['duplicates']])
                                        @endif
                                    </div>
                                @endif

                                @if ($balances['closing'] || $balances['opening'])
                                    <ul class="bkim-note pl-3 mt-2 mb-0">
                                        @if ($balances['opening'])
                                            <li>
                                                @if ($balances['opening']['recorded'])
                                                    @lang($L . 'review.balance_opening', ['amount' => $money($balances['opening']['amount']), 'date' => $date($balances['opening']['date'])])
                                                @else
                                                    @lang($L . 'review.balance_opening_skipped', ['date' => $date($balances['opening']['date'])])
                                                @endif
                                            </li>
                                        @endif
                                        @if ($balances['closing'])
                                            <li>
                                                @lang($L . 'review.balance_closing', ['amount' => $money($balances['closing']['amount']), 'date' => $date($balances['closing']['date'])])
                                                @if (!empty($balances['closing']['covered']))
                                                    <span class="bkim-warn">@lang($L . 'review.balance_closing_covered')</span>
                                                @elseif ($balances['closing']['existing_count'] > 0)
                                                    <span class="bkim-warn">
                                                        @lang($L . 'review.balance_closing_existing', ['count' => $balances['closing']['existing_count'], 'sum' => $money($balances['closing']['existing_sum'])])
                                                    </span>
                                                @endif
                                            </li>
                                        @endif
                                    </ul>
                                @endif
                            @endif
                        </div>

                        @if ($st['importable'] && $st['rows'])
                            <div class="table-responsive bkim-scroll border-top">
                                <table class="table table-sm table-hover mb-0 bkim-table" id="bkim-table-{{ $position }}">
                                    <thead>
                                        <tr>
                                            <th class="pl-4" style="width: 36px"><input type="checkbox" class="bkim-select-all" checked title="{{ __($L . 'review.select_all') }}" aria-label="{{ __($L . 'review.select_all') }}"></th>
                                            <th>@lang($L . 'review.col_date')</th>
                                            <th>@lang($L . 'review.col_counterparty')</th>
                                            <th>@lang($L . 'review.col_description')</th>
                                            <th>@lang($L . 'review.col_reference')</th>
                                            <th class="text-right">@lang($L . 'review.col_amount')</th>
                                            <th>@lang($L . 'review.col_account')</th>
                                            <th class="text-center" title="@lang($L . 'review.remember_help')">@lang($L . 'review.col_remember')</th>
                                            <th class="pr-4">@lang($L . 'review.col_status')</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($st['rows'] as $row)
                                            @php
                                                if ($rendered >= $maxRows) { $truncated = true; break; }
                                                $rendered++;
                                                $tx = $row['tx'];
                                                $dir = $tx['direction'];
                                                $match = $row['match'];
                                                $options = $dir === 'credit' ? $creditAccounts : $debitAccounts;
                                                $initial = $match['account_id'] ?? ($review['defaults'][$dir] ?? null);
                                                $key = $dir . '|' . \App\Services\Databackd\Gaps\BankImport\CounterpartyMatcher::normalizeText($tx['counterparty_name'] ?: ($tx['counterparty_iban'] ?? ''));
                                                $identity = ($tx['counterparty_name'] ?? '') !== '' || ($tx['counterparty_iban'] ?? '') !== '';
                                            @endphp
                                            <tr class="bkim-row @if ($row['duplicate']) bkim-off @endif" data-idx="{{ $row['idx'] }}" data-dir="{{ $dir }}"
                                                data-key="{{ $key }}" data-matched="{{ $match ? 1 : 0 }}" data-initial="{{ $initial ?: 0 }}"
                                                data-default-include="{{ $row['duplicate'] ? 0 : 1 }}" data-duplicate="{{ $row['duplicate'] ? 1 : 0 }}"
                                                data-search="{{ mb_strtolower(implode(' ', array_filter([$tx['counterparty_name'] ?? '', $tx['counterparty_iban'] ?? '', $tx['description'] ?? '', $tx['reference'] ?? '', $tx['bank_reference'] ?? '']))) }}">
                                                <td class="pl-4"><input type="checkbox" class="bkim-include" @checked(!$row['duplicate'])
                                                           aria-label="{{ __($L . 'review.include_row', ['date' => $date($tx['date']), 'amount' => $money(abs($tx['amount']))]) }}"></td>
                                                <td class="text-nowrap">{{ $date($tx['date']) }}</td>
                                                <td>
                                                    {{ $tx['counterparty_name'] ?: '—' }}
                                                    @if (!empty($tx['counterparty_iban']))
                                                        <span class="bkim-iban">{{ $tx['counterparty_iban'] }}</span>
                                                    @endif
                                                </td>
                                                <td class="bkim-desc" title="{{ $tx['description'] }}">{{ $tx['description'] }}</td>
                                                <td class="text-nowrap f-12 text-dark-grey">{{ $tx['reference'] ?: ($tx['bank_reference'] ?? '') }}</td>
                                                <td class="bkim-amount {{ $tx['amount'] >= 0 ? 'bkim-in' : 'bkim-out' }}">{{ $tx['amount'] >= 0 ? '+' : '−' }}{{ $money(abs($tx['amount'])) }}</td>
                                                <td>
                                                    <select class="form-control form-control-sm bkim-account" aria-label="{{ __($L . 'review.col_account') }}">
                                                        <option value="0">@lang($L . 'review.skip')</option>
                                                        @foreach ($options as $account)
                                                            <option value="{{ $account['id'] }}" @selected($initial == $account['id'])>{{ $accountLabel($account) }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                                <td class="text-center">
                                                    <input type="checkbox" class="bkim-remember" @disabled(!$identity) title="{{ __($L . 'review.remember_help') }}"
                                                           aria-label="{{ __($L . 'review.col_remember') }}">
                                                </td>
                                                <td class="pr-4 text-nowrap">
                                                    @if ($row['duplicate'])
                                                        <span class="badge badge-warning bkim-status" data-state="duplicate">@lang($L . 'review.status_duplicate')</span>
                                                    @elseif ($match)
                                                        <span class="badge badge-success bkim-status" data-state="rule">@lang($L . 'review.status_rule')</span>
                                                    @else
                                                        <span class="badge badge-light bkim-status" data-state="default">@lang($L . 'review.status_default')</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        <tr class="bkim-filter-empty"><td colspan="9" class="text-center text-lightest py-3">@lang($L . 'review.filter_empty')</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        @elseif ($st['importable'])
                            <div class="px-4 pb-4 bkim-note">@lang($L . 'review.no_transactions')</div>
                        @endif
                    </x-cards.data>
                @endforeach

                @if ($truncated)
                    <p class="bkim-note mt-2"><i class="fa fa-info-circle mr-1"></i>@lang($L . 'review.truncated', ['max' => $maxRows])</p>
                @endif

                @if ($review['has_overlap'])
                    <div class="custom-control custom-checkbox mt-3">
                        <input type="checkbox" class="custom-control-input" name="allow_overlap" id="allow_overlap" value="1">
                        <label class="custom-control-label f-14 cursor-pointer" for="allow_overlap">@lang($L . 'review.overlap_confirm')</label>
                    </div>
                @endif

                <div class="mt-3 mb-4">
                    <x-forms.button-primary id="bank-import-run-btn" icon="check" :disabled="$review['importable'] === 0">
                        {{ __($L . 'review.import_button') }}
                    </x-forms.button-primary>
                    <x-forms.link-secondary :link="route('databackd.gaps.bank-import.index')" icon="arrow-left" class="ml-2">
                        {{ __($L . 'review.back') }}
                    </x-forms.link-secondary>
                    <span class="bkim-note ml-3" id="bank-import-count"></span>
                </div>
            </x-form>
        @endif
    </div>
@endsection

@push('scripts')
    @if (!empty($meta))
        <script>
            $(function () {
                var hasOverlap = {{ $review['has_overlap'] ? 'true' : 'false' }};
                var importable = {{ $review['importable'] }};
                var countText = @json(__($L . 'review.import_count'));
                var bulkTexts = {
                    choose: @json(__($L . 'review.bulk_need_account')),
                    done: @json(__($L . 'review.bulk_done')),
                    other: @json(__($L . 'review.bulk_other_direction'))
                };

                function defaultFor(dir) {
                    return parseInt($(dir === 'credit' ? '#default_credit' : '#default_debit').val(), 10) || 0;
                }

                // account of a row without a manual choice: the rule's account, or the default account of its direction
                function baseValue($row) {
                    return $row.data('matched') ? parseInt($row.data('initial'), 10) : defaultFor($row.data('dir'));
                }

                function updateStatus($row) {
                    var $badge = $row.find('.bkim-status');

                    if ($badge.data('state') === 'duplicate') {
                        return;
                    }

                    var value = parseInt($row.find('.bkim-account').val(), 10) || 0;
                    var manual = !!$row.find('.bkim-account').data('manual');
                    var state = manual ? 'manual' : ($row.data('matched') ? 'rule' : (value ? 'default' : 'skip'));
                    var map = {
                        rule: ['badge-success', @json(__($L . 'review.status_rule'))],
                        manual: ['badge-primary', @json(__($L . 'review.status_manual'))],
                        default: ['badge-light', @json(__($L . 'review.status_default'))],
                        skip: ['badge-secondary', @json(__($L . 'review.status_skip'))]
                    };

                    if (value === 0 && state !== 'skip') {
                        state = 'skip';
                    }

                    $badge.attr('class', 'badge bkim-status ' + map[state][0]).data('state', state).text(map[state][1]);
                }

                var activeFilter = 'all';

                // which filters a row belongs to: unmatched = no counterparty rule, skipped = not ticked or "do not import"
                function rowFilters($row) {
                    var on = $row.find('.bkim-include').is(':checked');
                    var value = parseInt($row.find('.bkim-account').val(), 10) || 0;

                    return {
                        all: true,
                        unmatched: !$row.data('matched'),
                        skipped: !on || !value,
                        duplicate: $row.data('duplicate') === 1
                    };
                }

                function applyFilter() {
                    var term = ($('#bkim-search').val() || '').toLowerCase().trim();

                    $('.bkim-table').each(function () {
                        var shown = 0;

                        $(this).find('.bkim-row').each(function () {
                            var $row = $(this);
                            var visible = rowFilters($row)[activeFilter] && (term === '' || String($row.attr('data-search') || '').indexOf(term) !== -1);

                            $row.toggle(visible);
                            shown += visible ? 1 : 0;
                        });

                        $(this).find('.bkim-filter-empty').toggle(shown === 0);
                    });
                }

                function refresh() {
                    var selected = 0, unmatched = 0, imported = 0;
                    var counts = {all: 0, unmatched: 0, skipped: 0, duplicate: 0};

                    $('.bkim-row').each(function () {
                        var $row = $(this);
                        var on = $row.find('.bkim-include').is(':checked');
                        var value = parseInt($row.find('.bkim-account').val(), 10) || 0;
                        var filters = rowFilters($row);

                        $row.toggleClass('bkim-off', !on);

                        $.each(counts, function (key) {
                            counts[key] += filters[key] ? 1 : 0;
                        });

                        if (on) {
                            selected++;
                            imported += value ? 1 : 0;
                            // same meaning as the server value of the card: ticked rows without a counterparty rule
                            unmatched += $row.data('matched') ? 0 : 1;
                        }
                    });

                    $('#bkim-stat-selected').text(selected);
                    $('#bkim-stat-unmatched').text(unmatched);
                    $('#bank-import-count').text(countText.replace(':count', imported));
                    $.each(counts, function (key, count) {
                        $('.bkim-count[data-count="' + key + '"]').text(' ' + count);
                    });

                    var blocked = importable === 0 || (hasOverlap && !$('#allow_overlap').is(':checked'));
                    $('#bank-import-run-btn').prop('disabled', blocked);
                    // the filter is applied only when it (or the search) changes: a row does not jump away while it is edited
                }

                $('.bkim-filter').on('click', function () {
                    activeFilter = $(this).data('filter');
                    $('.bkim-filter').removeClass('is-active').attr('aria-pressed', 'false');
                    $(this).addClass('is-active').attr('aria-pressed', 'true');
                    applyFilter();
                });

                $('#bkim-search').on('input', applyFilter);

                // bulk: the chosen account goes to the ticked, visible rows of the same direction (revenue -> receipts, expense -> payments)
                $('#bkim-bulk-apply').on('click', function () {
                    var $option = $('#bkim-bulk-account option:selected');
                    var value = $('#bkim-bulk-account').val();

                    if (value === '' || value === undefined) {
                        $('#bkim-bulk-result').text(bulkTexts.choose);
                        $('#bkim-bulk-account').trigger('focus');
                        return;
                    }

                    var dir = $option.data('dir');
                    var changed = 0, other = 0;

                    $('.bkim-row:visible').each(function () {
                        var $row = $(this);

                        if (!$row.find('.bkim-include').is(':checked')) {
                            return;
                        }

                        if (dir !== 'any' && $row.data('dir') !== dir) {
                            other++;
                            return;
                        }

                        $row.find('.bkim-account').val(value).data('manual', true);
                        updateStatus($row);
                        changed++;
                    });

                    $('#bkim-bulk-result').text(bulkTexts.done.replace(':count', changed) + (other ? ' ' + bulkTexts.other.replace(':count', other) : ''));
                    refresh();
                });

                // default accounts: rows without a rule and without a manual choice follow them
                $('#default_credit, #default_debit').on('change', function () {
                    var dir = this.id === 'default_credit' ? 'credit' : 'debit';
                    var value = defaultFor(dir);

                    $('.bkim-row[data-dir="' + dir + '"][data-matched="0"]').each(function () {
                        var $select = $(this).find('.bkim-account');

                        if (!$select.data('manual')) {
                            $select.val(value);
                            updateStatus($(this));
                        }
                    });

                    refresh();
                });

                $('body').on('change', '.bkim-row .bkim-account', function () {
                    var $row = $(this).closest('tr');

                    $(this).data('manual', true);
                    updateStatus($row);

                    if ($row.find('.bkim-remember').is(':checked')) {
                        propagate($row, parseInt($(this).val(), 10) || 0);
                    }

                    refresh();
                });

                // "remember": the other rows of the same counterparty follow the choice (the import creates the rule first)
                function sameCounterparty($row) {
                    var key = $row.data('key');

                    return $('.bkim-row').not($row).filter(function () {
                        return $(this).data('key') === key && $(this).data('dir') === $row.data('dir');
                    });
                }

                function propagate($row, value) {
                    sameCounterparty($row).each(function () {
                        var $select = $(this).find('.bkim-account');

                        if (!$select.data('manual')) {
                            $select.val(value);
                            updateStatus($(this));
                        }
                    });
                }

                $('body').on('change', '.bkim-row .bkim-remember', function () {
                    var $row = $(this).closest('tr');

                    if ($(this).is(':checked')) {
                        propagate($row, parseInt($row.find('.bkim-account').val(), 10) || 0);
                    } else {
                        sameCounterparty($row).each(function () {
                            var $select = $(this).find('.bkim-account');

                            if (!$select.data('manual')) {
                                $select.val(baseValue($(this)));
                                updateStatus($(this));
                            }
                        });
                    }

                    refresh();
                });

                $('body').on('change', '.bkim-row .bkim-include', refresh);

                $('.bkim-select-all').on('change', function () {
                    var on = $(this).is(':checked');

                    // only the rows the current filter / search shows
                    $(this).closest('table').find('.bkim-row:visible .bkim-include').prop('checked', on);
                    refresh();
                });

                $('#allow_overlap').on('change', refresh);

                $('#entity_id').on('change', function () {
                    window.location.href = "{{ route('databackd.gaps.bank-import.review') }}?token={{ $token }}&entity_id=" + encodeURIComponent($(this).val());
                });

                // only the choices that differ from the suggestions are sent
                function decisions() {
                    var d = {default_credit: defaultFor('credit'), default_debit: defaultFor('debit'), accounts: {}, include: {}, remember: {}};

                    $('.bkim-row').each(function () {
                        var $row = $(this);
                        var idx = $row.data('idx');
                        var $select = $row.find('.bkim-account');
                        var on = $row.find('.bkim-include').is(':checked');

                        var value = parseInt($select.val(), 10) || 0;

                        // a manual choice, or a value that differs from what the server would suggest (e.g. taken over
                        // from a "remember" row of the same counterparty) - so the import books exactly what is shown
                        if ($select.data('manual') || value !== baseValue($row)) {
                            d.accounts[idx] = value;
                        }

                        if (on !== ($row.data('default-include') === 1)) {
                            d.include[idx] = on ? 1 : 0;
                        }

                        if ($row.find('.bkim-remember').is(':checked')) {
                            d.remember[idx] = 1;
                        }
                    });

                    return d;
                }

                $('#bank-import-run-btn').on('click', function () {
                    $('#decisions').val(JSON.stringify(decisions()));

                    $.easyAjax({
                        url: "{{ route('databackd.gaps.bank-import.run') }}",
                        container: '#bank-import-run-form',
                        type: 'POST',
                        disableButton: true,
                        blockUI: true,
                        buttonSelector: '#bank-import-run-btn',
                        data: $('#bank-import-run-form').serialize(),
                        success: function (response) {
                            if (response.status === 'success') {
                                window.location.href = response.redirect_url;
                            }
                        }
                    });
                });

                refresh();
                applyFilter();
            });
        </script>
    @endif
@endpush
