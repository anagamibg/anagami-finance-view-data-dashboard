<?php

namespace App\Http\Controllers\Databackd\Data;

use App\DataTables\Databackd\Data\FinEntriesDataTable;
use App\Exports\Databackd\Data\FinEntriesExport;
use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Data\FinEntry\StoreFinEntry;
use App\Http\Requests\Databackd\Data\FinEntry\UpdateFinEntry;
use App\Models\Currency;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinDimension;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Services\Databackd\Data\FinEntryImporter;
use App\Services\Databackd\Data\InvoiceExpenseSync;
use App\Services\Databackd\Gaps\FxRates\FxRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class FinEntryController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-entries.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(FinEntriesDataTable $dataTable)
    {
        if (!request()->ajax()) {
            $this->loadLookups();
            $this->loadUrlFilters();
            $this->hasEntries = FinEntry::query()->exists();
            $this->importUrl = \Route::has('databackd.data.import.index') ? route('databackd.data.import.index') : null;
            $this->bulkUrl = \Route::has('databackd.data.bulk.index') ? route('databackd.data.bulk.index') : null;
        }

        return $dataTable->render('databackd.data.entries.index', $this->data);
    }

    public function create()
    {
        $this->entry = new FinEntry(['entry_date' => now(), 'kind' => 'flow']);

        // AFV (S12/A07): "Save and add next" passes the values of the previous entry that usually repeat - date,
        // legal entity, account, kind, currency and the dimensions (never the amount, counterparty, document or note).
        // An id of another company or a deleted lookup is simply not found among the options of the form.
        foreach (['entity_id', 'account_id', 'region_id', 'client_segment_id', 'product_group_id', 'cost_center_id'] as $column) {
            if (ctype_digit((string)request($column))) {
                $this->entry->{$column} = (int)request($column);
            }
        }

        if (in_array(request('kind'), FinEntry::KINDS, true)) {
            $this->entry->kind = request('kind');
        }

        if (preg_match('/^[A-Za-z]{3}$/', (string)request('currency'))) {
            $this->entry->currency = strtoupper(request('currency'));
        }

        if (request()->filled('entry_date')) {
            try {
                $this->entry->entry_date = \Carbon\Carbon::createFromFormat(company()->date_format, request('entry_date'));
            } catch (\Throwable $e) {
                // keep today
            }
        }

        $this->repeated = request()->filled('entry_date');
        $this->loadFormData();
        $this->pageTitle = __('databackd-data-entries.add_entry');

        return $this->renderForm();
    }

    public function store(StoreFinEntry $request)
    {
        $entry = new FinEntry();
        $entry->company_id = company()->id;
        $entry->created_by = user()->id;
        $entry->source = 'manual';
        $this->fill($entry, $request);
        $entry->save();

        return Reply::successWithData(__('databackd-data-entries.messages.saved'), ['redirectUrl' => route('databackd.data.entries.index')]);
    }

    public function edit($id)
    {
        $this->entry = FinEntry::findOrFail($id);
        $this->loadFormData();
        $this->pageTitle = __('databackd-data-entries.edit_entry');

        return $this->renderForm();
    }

    public function update(UpdateFinEntry $request, $id)
    {
        $entry = FinEntry::findOrFail($id);
        $this->fill($entry, $request);
        $entry->save();

        return Reply::successWithData(__('databackd-data-entries.messages.updated'), ['redirectUrl' => route('databackd.data.entries.index')]);
    }

    public function destroy($id)
    {
        FinEntry::findOrFail($id)->delete();

        return Reply::success(__('databackd-data-entries.messages.deleted'));
    }

    /**
     * Bulk actions from the list (POST ?row_ids=1,2,3, action_type=delete).
     */
    public function applyQuickAction(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$request->query('row_ids', $request->input('row_ids', '')))));

        if (!$ids) {
            return Reply::error(__('databackd-data-entries.messages.select_rows'));
        }

        if ($request->input('action_type') !== 'delete') {
            return Reply::error(__('databackd-data-entries.messages.select_action'));
        }

        $count = FinEntry::whereIn('id', $ids)->delete();

        return Reply::success(__('databackd-data-entries.messages.deleted_many', ['count' => $count]));
    }

    /**
     * Excel export of the rows matching the list filters.
     */
    public function export(Request $request)
    {
        return Excel::download(new FinEntriesExport($request), 'financial-entries-' . now()->format('Y-m-d-His') . '.xlsx');
    }

    protected function renderForm()
    {
        $this->view = 'databackd.data.entries.ajax.form';

        if (request()->ajax()) {
            return $this->returnAjax($this->view);
        }

        return view('databackd.data.entries.page', $this->data);
    }

    protected function fill(FinEntry $entry, Request $request): void
    {
        $account = FinAccount::find($request->input('account_id'));
        abort_if(!$account, 422, __('databackd-data-entries.messages.invalid_account'));

        // databackd: an entry transferred from an invoice/expense keeps the fields of its document - they follow the document on
        // every sync, and a changed or cleared document_no would make the sync remove or duplicate it (InvoiceExpenseSync)
        $fromDocument = $entry->exists && in_array($entry->source, InvoiceExpenseSync::SOURCES, true);

        $entry->entity_id = (int)$request->input('entity_id');
        $entry->account_id = $account->id;
        $entry->kind = $request->input('kind');

        if (!$fromDocument) {
            $currency = strtoupper((string)$request->input('currency'));
            $amount = round((float)$request->input('amount'), 2);

            $entry->entry_date = companyToYmd($request->input('entry_date'));
            $entry->amount = $amount;
            $entry->currency = $currency;
            $entry->amount_base = self::convertToBase($amount, $currency, $entry->entry_date->toDateString());
        }

        foreach (['region_id', 'client_segment_id', 'product_group_id', 'cost_center_id'] as $column) {
            $entry->{$column} = $request->filled($column) ? (int)$request->input($column) : null;
        }

        foreach ($fromDocument ? ['description'] : ['counterparty_name', 'document_no', 'description'] as $column) {
            $value = trim((string)$request->input($column));
            $entry->{$column} = $value !== '' ? $value : null;
        }
    }

    public static function baseCurrency(): string
    {
        return company()->currency->currency_code ?? 'EUR';
    }

    /**
     * AFV (S12/A07): amount in the base currency with exactly the rules of the import (FinEntryImporter::toBase()):
     * same currency, the BGN/EUR peg, the rate of the entry date from "Exchange rates" (fin_fx_rates), the company rate
     * (S10/A2: multiplied). Before, manual entries ignored the dated rates. Also used by the bulk grid.
     */
    public static function convertToBase(float $amount, string $currency, string $date): float
    {
        return (new FinEntryImporter())->toBase($amount, strtoupper($currency), $date);
    }

    /**
     * Whether convertToBase() has a real rate for the currency on the date (instead of its 1:1 fallback).
     */
    public static function hasRate(string $currency, string $date): bool
    {
        $currency = strtoupper($currency);
        $base = self::baseCurrency();

        if ($currency === $base || (in_array($currency, ['BGN', 'EUR'], true) && in_array($base, ['BGN', 'EUR'], true))) {
            return true;
        }

        $dated = (new FxRateService())->rate($currency, $date, $base);

        if ($dated !== null && $dated > 0) {
            return true;
        }

        return (float)Currency::where('currency_code', $currency)->value('exchange_rate') > 0;
    }

    /**
     * Currency codes offered in the forms: the base currency, the BGN/EUR pair, the company currencies and every
     * currency with a stored exchange rate.
     */
    public static function currencyCodes(?string $current = null): \Illuminate\Support\Collection
    {
        $base = self::baseCurrency();

        return collect([$base])
            ->merge(in_array($base, ['BGN', 'EUR'], true) ? ['EUR', 'BGN'] : [])
            ->merge(Currency::query()->pluck('currency_code'))
            ->merge((new FxRateService())->currencies($base))
            ->push($current ?: $base)
            ->map(fn($code) => strtoupper((string)$code))
            ->filter(fn($code) => preg_match('/^[A-Z]{3}$/', $code))
            ->unique()
            ->values();
    }

    /**
     * Lookups shared by the list filters.
     */
    protected function loadLookups(): void
    {
        $this->entities = FinEntity::orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency', 'is_active']);
        $this->accounts = FinAccount::orderBy('code')->get(['id', 'code', 'name', 'type']);
        $this->dimensions = FinDimension::orderBy('name')->get(['id', 'type', 'name'])->groupBy('type');
        $this->accountTypes = FinAccount::TYPES;
    }

    /**
     * Prefill of the list filters from the query string of a link (e.g. from the P&L): from, to (Y-m-d),
     * entity_id, account_id (one id or a comma separated list), plus batch_id as before. Invalid dates and ids, and
     * ids of other companies (the lookups are company scoped), are ignored. A single id fills the select of the
     * filter; several ids cannot be shown in a single select, so they are listed in a removable banner.
     */
    protected function loadUrlFilters(): void
    {
        // the date range control needs both ends: a link with only one valid date does not filter the page
        $from = FinEntriesDataTable::validDate(request('from'));
        $to = FinEntriesDataTable::validDate(request('to'));

        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $this->initialFrom = $from && $to ? $from : null;
        $this->initialTo = $from && $to ? $to : null;

        $entities = $this->entities->keyBy('id');
        $accounts = $this->accounts->keyBy('id');

        $entityIds = array_values(array_filter(FinEntriesDataTable::parseIds(request('entity_id')), fn($id) => $entities->has($id)));
        $accountIds = array_values(array_filter(FinEntriesDataTable::parseIds(request('account_id')), fn($id) => $accounts->has($id)));

        $this->initialEntityId = count($entityIds) === 1 ? $entityIds[0] : null;
        $this->initialAccountId = count($accountIds) === 1 ? $accountIds[0] : null;

        $this->urlEntityIds = count($entityIds) > 1 ? $entityIds : [];
        $this->urlAccountIds = count($accountIds) > 1 ? $accountIds : [];
        $this->urlEntitiesText = Str::limit(collect($this->urlEntityIds)->map(fn($id) => $entities[$id]->name)->implode(', '), 160);
        $this->urlAccountsText = Str::limit(collect($this->urlAccountIds)->map(fn($id) => $accounts[$id]->code)->implode(', '), 160);
        $this->initialBatchId = (int)request('batch_id') ?: null;
    }

    protected function loadFormData(): void
    {
        $this->loadLookups();

        $this->accounts = FinAccount::where(function ($query) {
            $query->where('is_active', true)->orWhere('id', $this->entry->account_id);
        })->orderBy('code')->get(['id', 'code', 'name', 'type']);

        // inactive legal entities are hidden from data entry, except the one of the edited entry
        $this->entities = $this->entities->filter(fn($entity) => $entity->is_active || $entity->id === $this->entry->entity_id)->values();

        $this->baseCurrency = self::baseCurrency();
        $this->currencies = self::currencyCodes($this->entry->currency);
        $this->inModal = request()->ajax();
        $this->repeated = $this->repeated ?? false;
    }

}
