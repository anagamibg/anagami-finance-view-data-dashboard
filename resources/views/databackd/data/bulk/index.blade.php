@extends('layouts.app')

@section('content')
    <!-- CONTENT WRAPPER START -->
    <div class="content-wrapper dbk-data-page dbk-bulk">

        <x-dbk.page-header :subtitle="__('databackd-data-bulk.subtitle')">
            @if ($entriesUrl)
                <x-slot name="actions">
                    <a href="{{ $entriesUrl }}" class="btn btn-secondary rounded f-14 p-2">
                        <i class="fa fa-list mr-1"></i>{{ __('databackd-data-bulk.buttons.entries') }}
                    </a>
                </x-slot>
            @endif
        </x-dbk.page-header>

        <div class="dbk-card dbk-bulk-filters mb-3">
            <div class="dbk-card-body">
            @if ($entities->isEmpty())
                <div class="alert alert-warning mb-0">{{ __('databackd-data-bulk.empty_entities') }}</div>
            @else
                <div class="row">
                    <div class="col-lg-3 col-md-6 mb-2">
                        <label class="f-14 text-dark-grey mb-1" for="bulk-entity">{{ __('databackd-data-bulk.entity') }}</label>
                        <select id="bulk-entity" class="form-control">
                            @foreach ($entities as $entity)
                                <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-2">
                        <label class="f-14 text-dark-grey mb-1" for="bulk-month">{{ __('databackd-data-bulk.month') }}</label>
                        <input type="month" id="bulk-month" class="form-control f-14" value="{{ $defaultMonth }}" min="2000-01" max="2099-12">
                    </div>
                    <div class="col-lg-3 col-md-6 mb-2">
                        <label class="f-14 text-dark-grey mb-1" for="bulk-dim-type">{{ __('databackd-data-bulk.dimension_type') }}</label>
                        <select id="bulk-dim-type" class="form-control">
                            <option value="none">{{ __('databackd-data-bulk.dimension_none') }}</option>
                            @foreach (['region', 'client_segment', 'product_group', 'cost_center'] as $type)
                                <option value="{{ $type }}">{{ __('databackd-data-common.dimension_types.' . $type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-4 col-md-6 mb-2 bulk-dim-wrap" id="bulk-dim-default-wrap" style="display:none">
                        <label class="f-14 text-dark-grey mb-1" for="bulk-dim-default">{{ __('databackd-data-bulk.dimension_value') }}</label>
                        <select id="bulk-dim-default" class="form-control"></select>
                    </div>
                </div>
                <div class="f-12 text-lightest" id="bulk-dim-hint" style="display:none">{{ __('databackd-data-bulk.dimension_hint') }}</div>
                <div class="f-12 text-lightest mt-1" id="bulk-date-note"></div>
            @endif
            </div>
        </div>

        @if ($entities->isNotEmpty())
            {{-- S12/A07: number of rows with errors above the table (focus moves here, then to the first wrong row) --}}
            <div class="alert alert-danger d-flex align-items-center mb-3" id="bulk-errors" role="alert" tabindex="-1" style="display:none !important">
                <i class="fa fa-exclamation-circle mr-2" aria-hidden="true"></i>
                <span id="bulk-errors-text"></span>
                <button type="button" class="btn btn-link btn-sm ml-auto p-0" id="bulk-errors-first">{{ __('databackd-data-bulk.buttons.first_error') }}</button>
            </div>

            {{-- S12 review A: a month whose grid entries are in several currencies is read-only here (the grid saves one currency) --}}
            <div class="alert alert-warning d-flex align-items-start mb-3" id="bulk-mixed" role="status" style="display:none !important">
                <i class="fa fa-exclamation-triangle mr-2 mt-1" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div id="bulk-mixed-text"></div>
                    <a href="#" class="btn btn-secondary btn-sm rounded f-14 mt-2" id="bulk-mixed-link"><i class="fa fa-list mr-1" aria-hidden="true"></i>{{ __('databackd-data-bulk.buttons.month_entries') }}</a>
                </div>
            </div>

            <p class="dbk-data-hint mb-2 d-none d-md-block" id="bulk-keys-hint"><i class="fa fa-keyboard mr-1" aria-hidden="true"></i>{{ __('databackd-data-bulk.keys_hint') }}</p>

            <div class="dbk-card mb-3">
                <div id="bulk-grid-wrap">
                    <div id="bulk-loading"><span class="dbk-loader mr-2" aria-hidden="true"></span>{{ __('databackd-data-bulk.loading') }}</div>
                    <div class="dbk-table-wrap is-flush dbk-bulk-scroll">
                        <table class="table bulk-table bulk-nodim" id="bulk-table">
                            <thead>
                            <tr>
                                <th>{{ __('databackd-data-bulk.columns.code') }}</th>
                                <th>{{ __('databackd-data-bulk.columns.account') }}</th>
                                <th class="text-right bulk-amount-col">{{ __('databackd-data-bulk.columns.amount') }} (<span class="bulk-currency">EUR</span>)</th>
                                <th class="bulk-dim-col">{{ __('databackd-data-bulk.columns.dimension') }}</th>
                                <th class="bulk-note-col">{{ __('databackd-data-bulk.columns.note') }}</th>
                                <th>{{ __('databackd-data-bulk.columns.existing') }}</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="bulk-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="dbk-card mb-3 dbk-bulk-footer">
                <div class="dbk-card-header">
                    <h3 class="dbk-card-title">{{ __('databackd-data-bulk.totals.title') }}</h3>
                    <div class="dbk-card-actions">
                        <span class="bulk-state badge badge-light" id="bulk-state">{{ __('databackd-data-bulk.messages.saved_state') }}</span>
                    </div>
                </div>
                <div class="dbk-card-body">
                <div class="bulk-totals">
                    <div class="bulk-total-box"><div class="bulk-total-label">{{ __('databackd-data-bulk.totals.revenue') }}</div><div class="bulk-total-value" id="tot-revenue">0,00</div></div>
                    <div class="bulk-total-box"><div class="bulk-total-label">{{ __('databackd-data-bulk.totals.expense') }}</div><div class="bulk-total-value" id="tot-expense">0,00</div></div>
                    <div class="bulk-total-box"><div class="bulk-total-label">{{ __('databackd-data-bulk.totals.result') }}</div><div class="bulk-total-value" id="tot-result">0,00</div></div>
                    <div class="bulk-total-box"><div class="bulk-total-label">{{ __('databackd-data-bulk.totals.assets') }}</div><div class="bulk-total-value" id="tot-assets">0,00</div></div>
                    <div class="bulk-total-box"><div class="bulk-total-label">{{ __('databackd-data-bulk.totals.liabilities_equity') }}</div><div class="bulk-total-value" id="tot-le">0,00</div></div>
                    <div class="bulk-total-box"><div class="bulk-total-label">{{ __('databackd-data-bulk.totals.difference') }}</div><div class="bulk-total-value" id="tot-diff">0,00</div></div>
                </div>
                <div class="dbk-data-form-actions">
                    <x-forms.button-primary id="bulk-save" icon="check">{{ __('databackd-data-bulk.buttons.save') }}</x-forms.button-primary>
                    <x-forms.button-secondary id="bulk-clear" icon="eraser">{{ __('databackd-data-bulk.buttons.clear') }}</x-forms.button-secondary>
                    @if ($entriesUrl)
                        <a href="{{ $entriesUrl }}" class="btn btn-secondary rounded f-14 p-2">
                            <i class="fa fa-list mr-1"></i>{{ __('databackd-data-bulk.buttons.entries') }}
                        </a>
                    @endif
                </div>
                </div>
            </div>
        @endif
    </div>
    <!-- CONTENT WRAPPER END -->
@endsection

@if ($entities->isNotEmpty())
@push('scripts')
    <script>
        (function () {
            var CFG = {
                loadUrl: @json(route('databackd.data.bulk.load')),
                storeUrl: @json(route('databackd.data.bulk.store')),
                csrf: @json(csrf_token()),
                dims: @json($dimensions),
                t: {
                    groups: @json(__('databackd-data-bulk.groups')),
                    contra: @json(__('databackd-data-bulk.contra')),
                    amountPh: @json(__('databackd-data-bulk.amount_placeholder')),
                    notePh: @json(__('databackd-data-bulk.note_placeholder')),
                    existing: @json(__('databackd-data-bulk.existing_info')),
                    addSplit: @json(__('databackd-data-bulk.add_split')),
                    removeSplit: @json(__('databackd-data-bulk.remove_split')),
                    noDim: @json(__('databackd-data-bulk.dimension_none_option')),
                    dateNote: @json(__('databackd-data-bulk.date_note')),
                    emptyAccounts: @json(__('databackd-data-bulk.empty_accounts')),
                    loadFailed: @json(__('databackd-data-bulk.messages.load_failed')),
                    saveFailed: @json(__('databackd-data-bulk.messages.save_failed')),
                    invalidRows: @json(__('databackd-data-bulk.messages.invalid_rows')),
                    invalidRowsCount: @json(__('databackd-data-bulk.messages.invalid_rows_count')),
                    invalidAmount: @json(__('databackd-data-bulk.messages.invalid_amount')),
                    pasted: @json(__('databackd-data-bulk.messages.pasted')),
                    pasteUnknown: @json(__('databackd-data-bulk.messages.paste_unknown')),
                    pasteOverflow: @json(__('databackd-data-bulk.messages.paste_overflow')),
                    confirmDiscard: @json(__('databackd-data-bulk.messages.confirm_discard')),
                    confirmClear: @json(__('databackd-data-bulk.messages.confirm_clear')),
                    unsaved: @json(__('databackd-data-bulk.messages.unsaved')),
                    savedState: @json(__('databackd-data-bulk.messages.saved_state')),
                    mixedTotal: @json(__('databackd-data-bulk.messages.mixed_total')),
                    yes: @json(__('databackd-data-bulk.buttons.confirm')),
                    cancel: @json(__('databackd-data-bulk.buttons.cancel'))
                }
            };

            var $body = $('#bulk-body'), $table = $('#bulk-table');
            var dirty = false;
            var current = {entity: $('#bulk-entity').val(), month: $('#bulk-month').val(), dimType: 'none', currency: ''};
            var locked = false; // S12 review A: month with grid entries in several currencies -> read-only
            var suppress = false;
            var saving = false;
            var uid = 0;

            function esc(s) {
                return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
                    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
                });
            }

            function fmt(n) {
                var neg = n < 0;
                var parts = Math.abs(n).toFixed(2).split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                return (neg ? '-' : '') + parts[0] + ',' + parts[1];
            }

            // Same rules as the server: "1234.56", "1234,56", "1 234,56", "1.234,56", "1,234.56". Returns null when invalid.
            function parseAmt(v) {
                var s = String(v).replace(/[\s  ]|€|EUR|eur|лв\.?/g, '');
                if (s === '' || !/^[+-]?[\d.,]+$/.test(s)) return null;
                var neg = s.charAt(0) === '-';
                s = s.replace(/^[+-]/, '');
                var c = s.lastIndexOf(','), d = s.lastIndexOf('.');
                if (c !== -1 && d !== -1) {
                    var dec = c > d ? ',' : '.', th = dec === ',' ? '.' : ',';
                    s = s.split(th).join('').replace(dec, '#').replace('#', '.');
                } else if (c !== -1) {
                    s = (s.split(',').length - 1) > 1 ? s.split(',').join('') : s.replace(',', '.');
                } else if (d !== -1 && (s.split('.').length - 1) > 1) {
                    s = s.split('.').join('');
                }
                var n = parseFloat(s);
                if (isNaN(n) || n >= 1e12) return null;
                n = Math.round(n * 100) / 100;
                return neg ? -n : n;
            }

            function notify(type, text) {
                if (window.Swal && Swal.fire) {
                    Swal.fire({toast: true, position: 'top-end', icon: type, title: text, showConfirmButton: false, timer: 4000});
                } else {
                    alert(text);
                }
            }

            function confirmDialog(text) {
                if (window.Swal && Swal.fire) {
                    return Swal.fire({text: text, icon: 'warning', showCancelButton: true, confirmButtonText: CFG.t.yes, cancelButtonText: CFG.t.cancel})
                        .then(function (r) { return !!r.isConfirmed; });
                }
                return Promise.resolve(window.confirm(text));
            }

            function setDirty(v) {
                dirty = v;
                $('#bulk-state').toggleClass('badge-warning', v).toggleClass('badge-light', !v).text(v ? CFG.t.unsaved : CFG.t.savedState);
            }

            function dimOptions(dimType, selected) {
                var html = '<option value="">' + esc(CFG.t.noDim) + '</option>';
                (CFG.dims[dimType] || []).forEach(function (d) {
                    html += '<option value="' + d.id + '"' + (String(selected) === String(d.id) ? ' selected' : '') + '>' + esc(d.name) + '</option>';
                });
                return html;
            }

            function initSelect2($el) {
                if ($.fn.select2) {
                    $el.select2({width: '100%', minimumResultsForSearch: 8});
                }
            }

            function rowHtml(acc, row, split) {
                var id = ++uid;
                var tag = acc.contra ? ' <span class="badge badge-light text-muted">' + esc(CFG.t.contra) + '</span>' : '';
                var existing = '';
                if (!split) {
                    existing = (acc.others || []).map(function (o) {
                        return '<div>' + esc(CFG.t.existing.replace(':source', o.label).replace(':amount', fmt(o.amount))) + '</div>';
                    }).join('');
                }
                var actions = locked ? '' : (split
                    ? '<a href="javascript:;" class="bulk-remove text-danger" title="' + esc(CFG.t.removeSplit) + '"><i class="fa fa-times"></i></a>'
                    : '<a href="javascript:;" class="bulk-add text-primary bulk-dim-only" title="' + esc(CFG.t.addSplit) + '"><i class="fa fa-plus-circle"></i></a>');
                // currency of a saved row when it is not the one of the grid (only in a read-only month with mixed currencies)
                var rowCur = row && row.currency && (locked || row.currency !== current.currency) ? row.currency : '';

                return '<tr class="bulk-row' + (split ? ' bulk-split' : '') + '" data-account="' + acc.id + '" data-code="' + esc(acc.code) + '" data-type="' + acc.type + '" data-contra="' + (acc.contra ? 1 : 0) + '"' +
                    ' data-entry="' + (row && row.entry_id ? row.entry_id : '') + '" data-touched="' + (row && row.entry_id ? 1 : 0) + '">' +
                    '<td class="bulk-code">' + (split ? '↳' : esc(acc.code)) + '</td>' +
                    '<td class="bulk-name">' + (split ? '' : esc(acc.name) + tag) + '</td>' +
                    '<td class="bulk-amount-col"><input type="text" inputmode="decimal" autocomplete="off" class="form-control form-control-sm bulk-amount" id="bulk-amount-' + id + '" aria-label="' + esc(acc.code + ' ' + (acc.name || '') + (rowCur ? ' (' + rowCur + ')' : '')) + '" placeholder="' + esc(CFG.t.amountPh) + '" value="' + (row && row.amount !== undefined ? esc(fmt(row.amount)) : '') + '"' + (locked ? ' disabled' : '') + '>' +
                    (rowCur ? '<div class="f-12 text-dark-grey text-right mt-1 bulk-row-currency">' + esc(rowCur) + '</div>' : '') + '</td>' +
                    '<td class="bulk-dim-col"><select class="bulk-dim" id="bulk-dim-' + id + '"' + (locked ? ' disabled' : '') + '></select></td>' +
                    '<td class="bulk-note-col"><input type="text" maxlength="500" class="form-control form-control-sm bulk-note" placeholder="' + esc(CFG.t.notePh) + '" value="' + esc(row ? row.description : '') + '"' + (locked ? ' disabled' : '') + '></td>' +
                    '<td class="bulk-existing">' + existing + '</td>' +
                    '<td class="bulk-actions">' + actions + '</td></tr>';
            }

            function fillDim($tr, dimType, value) {
                var $sel = $tr.find('.bulk-dim');
                if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
                $sel.html(dimOptions(dimType, value));
                if (dimType !== 'none') initSelect2($sel);
            }

            function render(data) {
                current.dimType = data.dim_type;
                current.currency = data.currency || '';
                var mixed = data.mixed_currencies || [];
                locked = mixed.length > 1;
                $body.find('.bulk-dim.select2-hidden-accessible').select2('destroy');
                $body.empty();
                $('.bulk-currency').text(locked ? mixed.join(' / ') : (data.currency || 'EUR'));
                $('#bulk-mixed-text').text(locked ? (data.mixed_message || '') : '');
                $('#bulk-mixed-link').attr('href', data.entries_url || '#').toggle(!!(locked && data.entries_url));
                $('#bulk-mixed').attr('style', locked ? '' : 'display:none !important');
                $('#bulk-save, #bulk-clear').prop('disabled', locked);
                $('#bulk-keys-hint').toggleClass('d-md-block', !locked);
                $table.toggleClass('bulk-nodim', data.dim_type === 'none');
                var def = $('#bulk-dim-default').val() || '';

                if (!data.groups.length) {
                    $body.append('<tr><td colspan="7" class="text-center text-lightest py-4">' + esc(CFG.t.emptyAccounts) + '</td></tr>');
                }

                data.groups.forEach(function (g) {
                    $body.append('<tr class="bulk-group-row"><td colspan="7">' + esc(CFG.t.groups[g.key] || g.key) + '</td></tr>');
                    g.accounts.forEach(function (acc) {
                        var rows = acc.rows.length ? acc.rows : [null];
                        rows.forEach(function (row, i) {
                            var $tr = $(rowHtml(acc, row, i > 0));
                            $body.append($tr);
                            fillDim($tr, data.dim_type, row ? row.dim_id : def);
                        });
                    });
                });

                $table.find('.bulk-dim-only').toggle(data.dim_type !== 'none');
                showSummary(0);
                $('#bulk-date-note').text(CFG.t.dateNote.replace(':date', data.date.split('-').reverse().join('.')));
                recalc();
                setDirty(false);
            }

            function recalc() {
                var $totals = $('#tot-revenue, #tot-expense, #tot-result, #tot-assets, #tot-le, #tot-diff');
                if (locked) {
                    // amounts in different currencies are not added up
                    $totals.text('—').attr('title', CFG.t.mixedTotal).removeClass('neg pos');
                    return;
                }
                $totals.removeAttr('title');
                var t = {revenue: 0, expense: 0, asset: 0, liability: 0, equity: 0};
                $body.find('tr.bulk-row').each(function () {
                    var $tr = $(this), v = $.trim($tr.find('.bulk-amount').val());
                    if (v === '') return;
                    var n = parseAmt(v);
                    if (n === null || n <= 0) return; // shown as an error when the user leaves the field / saves
                    t[$tr.data('type')] += $tr.data('contra') ? -n : n;
                });
                var result = t.revenue - t.expense, le = t.liability + t.equity, diff = t.asset - le;
                $('#tot-revenue').text(fmt(t.revenue));
                $('#tot-expense').text(fmt(t.expense));
                $('#tot-result').text(fmt(result)).toggleClass('neg', result < 0).toggleClass('pos', result > 0);
                $('#tot-assets').text(fmt(t.asset));
                $('#tot-le').text(fmt(le));
                $('#tot-diff').text(fmt(diff)).toggleClass('neg', Math.abs(diff) > 0.004).toggleClass('pos', false);
            }

            // ---- validation (S12/A07): on leaving the field, before saving and from the server; the text is next to the field
            function rowError($tr) {
                var v = $.trim($tr.find('.bulk-amount').val());
                if (v === '') return '';
                var n = parseAmt(v);
                return n === null || n <= 0 ? CFG.t.invalidAmount : '';
            }

            function markRow($tr, message) {
                var $input = $tr.find('.bulk-amount'), $cell = $tr.find('td.bulk-amount-col');
                var errorId = $input.attr('id') + '-error';
                $cell.find('.bulk-row-error').remove();
                $tr.toggleClass('bulk-invalid', !!message);
                $input.attr('aria-invalid', message ? 'true' : null);
                if (message) {
                    $cell.append($('<div class="bulk-row-error"></div>').attr('id', errorId).text(message));
                    $input.attr('aria-describedby', errorId);
                } else {
                    $input.removeAttr('aria-describedby');
                }
            }

            function showSummary(count) {
                $('#bulk-errors-text').text(CFG.t.invalidRowsCount.replace(':count', count));
                $('#bulk-errors').attr('style', count ? '' : 'display:none !important');
            }

            function focusFirstError() {
                var $first = $body.find('tr.bulk-invalid:visible').first().find('.bulk-amount');
                if (!$first.length) return;
                $first[0].scrollIntoView({block: 'center'});
                $first.trigger('focus');
            }

            function validateAll() {
                var count = 0;
                $body.find('tr.bulk-row:visible').each(function () {
                    var message = rowError($(this));
                    markRow($(this), message);
                    if (message) count++;
                });
                showSummary(count);
                return count;
            }

            function amountInputs() {
                return $body.find('tr.bulk-row:visible .bulk-amount');
            }

            // Paste from Excel / Google Sheets: several cells (TAB / new line) fill the following rows. Two or more columns
            // whose first one holds account codes of the grid fill the rows of those accounts (code, amount[, note]).
            function pasteCells(input, text) {
                var lines = text.replace(/\r\n?/g, '\n').replace(/\n+$/, '').split('\n');
                var cells = lines.map(function (line) { return line.split('\t'); });
                var byCode = {}, filled = 0, unknown = [], overflow = 0;

                $body.find('tr.bulk-row:not(.bulk-split)').each(function () { byCode[String($(this).data('code'))] = $(this); });

                var codeMode = cells.every(function (c) { return c.length >= 2; })
                    && cells.filter(function (c) { return byCode[$.trim(c[0])]; }).length >= Math.max(1, Math.ceil(cells.length / 2));

                var put = function ($tr, amount, note) {
                    if (!$tr || !$tr.length) return;
                    $tr.find('.bulk-amount').val($.trim(amount));
                    if (note !== undefined) $tr.find('.bulk-note').val($.trim(note));
                    var n = parseAmt(amount);
                    if (n !== null && n > 0) $tr.find('.bulk-amount').val(fmt(n));
                    markRow($tr, rowError($tr));
                    filled++;
                };

                if (codeMode) {
                    cells.forEach(function (c) {
                        var code = $.trim(c[0]);
                        if (code === '') return;
                        if (!byCode[code]) { unknown.push(code); return; }
                        put(byCode[code], c[1], c.length > 2 ? c[2] : undefined);
                    });
                } else {
                    var $rows = $body.find('tr.bulk-row:visible');
                    var start = $rows.index($(input).closest('tr'));
                    var inNote = $(input).hasClass('bulk-note');
                    cells.forEach(function (c, i) {
                        var $tr = $rows.eq(start + i);
                        if (start + i >= $rows.length) { overflow++; return; }
                        if (inNote) {
                            $tr.find('.bulk-note').val($.trim(c[0]));
                            filled++;
                        } else {
                            put($tr, c[0], c.length > 1 ? c[1] : undefined);
                        }
                    });
                }

                setDirty(true);
                recalc();
                var invalid = $body.find('tr.bulk-invalid:visible').length;
                showSummary(invalid);

                var message = CFG.t.pasted.replace(':count', filled);
                if (unknown.length) message += ' ' + CFG.t.pasteUnknown.replace(':codes', unknown.slice(0, 10).join(', '));
                if (overflow) message += ' ' + CFG.t.pasteOverflow.replace(':count', overflow);
                notify(unknown.length || overflow || invalid ? 'warning' : 'success', message);
            }

            function load() {
                $('#bulk-loading').css('display', 'flex');
                return $.ajax({
                    url: CFG.loadUrl, type: 'GET', dataType: 'json',
                    data: {entity_id: $('#bulk-entity').val(), month: $('#bulk-month').val(), dim_type: $('#bulk-dim-type').val()}
                }).done(function (data) {
                    if (data.status !== 'success') {
                        notify('error', data.message || CFG.t.loadFailed);
                        return;
                    }
                    current.entity = $('#bulk-entity').val();
                    current.month = $('#bulk-month').val();
                    render(data);
                }).fail(function () {
                    notify('error', CFG.t.loadFailed);
                }).always(function () {
                    $('#bulk-loading').hide();
                });
            }

            // Ask before throwing away unsaved input; on cancel restore the previous filter values.
            function guarded(revert) {
                if (!dirty) return Promise.resolve(true);
                return confirmDialog(CFG.t.confirmDiscard).then(function (ok) {
                    if (!ok) revert();
                    return ok;
                });
            }

            function setDimDefaultOptions(dimType) {
                var $d = $('#bulk-dim-default');
                if ($d.hasClass('select2-hidden-accessible')) $d.select2('destroy');
                $d.html(dimOptions(dimType, ''));
                $('#bulk-dim-default-wrap, #bulk-dim-hint').toggle(dimType !== 'none');
                if (dimType !== 'none') initSelect2($d);
            }

            // ---- events -------------------------------------------------------------------------------------------
            initSelect2($('#bulk-entity'));

            $('#bulk-entity').on('change', function () {
                if (suppress) return;
                guarded(function () { suppress = true; $('#bulk-entity').val(current.entity).trigger('change.select2'); suppress = false; })
                    .then(function (ok) { if (ok) load(); });
            });

            $('#bulk-month').on('change', function () {
                if (suppress || !this.value) return;
                guarded(function () { $('#bulk-month').val(current.month); }).then(function (ok) { if (ok) load(); });
            });

            $('#bulk-dim-type').on('change', function () {
                if (suppress) return;
                var next = this.value;
                guarded(function () { suppress = true; $('#bulk-dim-type').val(current.dimType); suppress = false; }).then(function (ok) {
                    if (!ok) return;
                    setDimDefaultOptions(next);
                    load();
                });
            });

            // Default breakdown value -> every row the user did not set by hand
            $('#bulk-dim-default').on('change', function () {
                var v = this.value;
                $body.find('tr.bulk-row').each(function () {
                    var $tr = $(this);
                    if (String($tr.attr('data-touched')) === '1') return;
                    $tr.find('.bulk-dim').val(v).trigger('change.select2');
                });
            });

            $body.on('input', '.bulk-amount', function () {
                var $tr = $(this).closest('tr');
                setDirty(true);
                recalc();
                // an error already shown disappears as soon as the value is fixed (it is not shown while typing)
                if ($tr.hasClass('bulk-invalid') && !rowError($tr)) {
                    markRow($tr, '');
                    showSummary($body.find('tr.bulk-invalid:visible').length);
                }
            });
            $body.on('blur', '.bulk-amount', function () {
                var $tr = $(this).closest('tr'), v = $.trim(this.value), n = v === '' ? null : parseAmt(v);
                if (n !== null && n > 0) this.value = fmt(n);
                markRow($tr, rowError($tr));
                if ($('#bulk-errors').is(':visible')) showSummary($body.find('tr.bulk-invalid:visible').length);
                recalc();
            });

            $body.on('paste', '.bulk-amount, .bulk-note', function (e) {
                var data = e.originalEvent && (e.originalEvent.clipboardData || window.clipboardData);
                var text = data ? data.getData('text') : '';
                // a single value is pasted as usual; a block of cells is spread over the rows
                if (!text || !/[\t\n]/.test(text.replace(/[\r\n]+$/, ''))) return;
                e.preventDefault();
                pasteCells(this, text);
            });

            // Keyboard: Enter / arrow down -> amount of the next row, Shift+Enter / arrow up -> previous row
            $body.on('keydown', '.bulk-amount', function (e) {
                var down = (e.key === 'Enter' && !e.shiftKey) || e.key === 'ArrowDown';
                var up = (e.key === 'Enter' && e.shiftKey) || e.key === 'ArrowUp';
                if (!down && !up) return;
                e.preventDefault();
                var $inputs = amountInputs(), i = $inputs.index(this);
                var $next = $inputs.eq(down ? i + 1 : Math.max(i - 1, 0));
                if ($next.length) $next.trigger('focus').trigger('select');
            });

            $(document).on('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                    e.preventDefault();
                    $('#bulk-save').trigger('click');
                }
            });

            $('#bulk-errors-first').on('click', focusFirstError);
            $body.on('input', '.bulk-note', function () { setDirty(true); });
            $body.on('change', '.bulk-dim', function () {
                $(this).closest('tr').attr('data-touched', 1);
                setDirty(true);
            });

            $body.on('click', '.bulk-add', function () {
                var $tr = $(this).closest('tr');
                var acc = {id: $tr.data('account'), type: $tr.data('type'), contra: $tr.data('contra') === 1, others: []};
                // insert after the last row of this account
                var $last = $body.find('tr.bulk-row[data-account="' + acc.id + '"]').last();
                var $new = $(rowHtml(acc, null, true));
                $last.after($new);
                fillDim($new, current.dimType, '');
                $new.find('.bulk-amount').trigger('focus');
                setDirty(true);
            });

            $body.on('click', '.bulk-remove', function () {
                var $tr = $(this).closest('tr');
                if ($tr.attr('data-entry')) {
                    // a saved record: keep the row hidden with an empty amount so Save deletes it
                    $tr.find('.bulk-amount').val('');
                    $tr.addClass('d-none');
                } else {
                    $tr.find('.bulk-dim.select2-hidden-accessible').select2('destroy');
                    $tr.remove();
                }
                setDirty(true);
                recalc();
            });

            $('#bulk-clear').on('click', function () {
                if (locked) return;
                confirmDialog(CFG.t.confirmClear).then(function (ok) {
                    if (!ok) return;
                    $body.find('.bulk-amount, .bulk-note').val('');
                    $body.find('tr.bulk-row').each(function () { markRow($(this), ''); });
                    showSummary(0);
                    setDirty(true);
                    recalc();
                });
            });

            $('#bulk-save').on('click', function () {
                var $btn = $(this), rows = [], $trs = [];

                if (saving || locked) return; // no double submit (button + Ctrl+S); a mixed-currency month is read-only

                recalc();

                if (validateAll()) {
                    $('#bulk-errors').trigger('focus');
                    focusFirstError();
                    return;
                }

                $body.find('tr.bulk-row').each(function () {
                    var $tr = $(this), amount = $.trim($tr.find('.bulk-amount').val()), entry = $tr.attr('data-entry');
                    if (amount === '' && !entry) return; // nothing to save or delete
                    rows.push({
                        account_id: $tr.data('account'),
                        entry_id: entry || '',
                        amount: amount,
                        dim_id: $tr.find('.bulk-dim').val() || '',
                        description: $tr.find('.bulk-note').val()
                    });
                    $trs.push($tr);
                });

                saving = true;
                $btn.prop('disabled', true);
                $('#bulk-loading').css('display', 'flex');

                $.ajax({
                    url: CFG.storeUrl, type: 'POST', dataType: 'json',
                    headers: {'X-CSRF-TOKEN': CFG.csrf},
                    // one JSON field: a long chart of accounts as form fields would exceed PHP max_input_vars
                    // currency: the one the amounts were typed in - the server refuses the save when the month has another one by now
                    data: {entity_id: current.entity, month: current.month, dim_type: current.dimType, currency: current.currency, rows_json: JSON.stringify(rows)}
                }).done(function (res) {
                    if (res.status === 'success') {
                        notify('success', res.message);
                        setDirty(false);
                        load();
                        return;
                    }
                    var inv = (res.data && res.data.invalid) || [];
                    inv.forEach(function (i) { if ($trs[i.index]) markRow($trs[i.index].removeClass('d-none'), i.message); });
                    if (inv.length) {
                        showSummary(inv.length);
                        focusFirstError();
                    }
                    notify('error', res.message || CFG.t.saveFailed);
                    $('#bulk-loading').hide();
                }).fail(function () {
                    notify('error', CFG.t.saveFailed);
                    $('#bulk-loading').hide();
                }).always(function () {
                    saving = false;
                    $btn.prop('disabled', false);
                });
            });

            $(window).on('beforeunload', function () { return dirty ? CFG.t.confirmDiscard : undefined; });

            setDimDefaultOptions('none');
            load();
        })();
    </script>
@endpush
@endif
