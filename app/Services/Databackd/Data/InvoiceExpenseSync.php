<?php

namespace App\Services\Databackd\Data;

use App\Models\Company;
use App\Models\CreditNotes;
use App\Models\Currency;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Copies paid invoices (revenue) and approved expenses (costs) from the existing AFV modules
 * into the universal `fin_entries` table (source = invoice | expense), so they appear in the financial reports.
 *
 * Rules
 *  - Invoices: status = paid, with at least one completed payment. One entry per invoice, dated on the invoice issue date,
 *    amount = net value (sub total minus discount; falls back to the total when that is not positive) minus the net value of
 *    the credit notes issued against the invoice (looked up for every invoice: AFV resets invoices.credit_note to 0 when one
 *    of several notes is deleted, so the flag is not reliable).
 *    Account: when goods (items matched by GOODS_KEYWORDS) make up at least half of the net value -> 702 "Приходи от продажба
 *    на стоки", otherwise 703 "Приходи от продажба на услуги".
 *  - Expenses: status = approved. One entry per expense dated on the purchase date. Account is picked from the category name
 *    by keywords (see CATEGORY_ACCOUNT_RULES), default 609 "Други разходи".
 *  - Idempotency: every entry carries a stable marker in `document_no` (INV-<invoice number> / EXP-<expense id>). A document whose
 *    marker already exists for the company (source invoice|expense) is never created again. When the document changed after the
 *    transfer (amount, date, currency, client) the entry is updated - its account, legal entity, dimensions and description are
 *    kept, the user may have changed them (the document fields are read-only in FinEntryController); an entry whose document no
 *    longer qualifies (deleted, cancelled, unpaid again, rejected) is removed, and so is every extra entry with the same marker.
 *    An invoice number reused by AFV after the last invoice was deleted therefore updates the old entry instead of being lost.
 *  - All amounts are positive; `amount_base` is the amount in the company base currency: equal to `amount` for documents in that
 *    currency; BGN <-> EUR at the fixed rate 1.95583 (old BGN documents after the euro switch on 2026-01-01); otherwise
 *    `amount * exchange_rate` of the document (the AFV convention) while that rate was recorded against the current base
 *    currency, else the dated rate of FinEntryImporter::toBase().
 */
class InvoiceExpenseSync
{

    /** fin_entries.source of the entries written by this sync. */
    public const SOURCES = ['invoice', 'expense'];

    /** Item name fragments (lower-case) that mark an invoice line as goods instead of a service. */
    public const GOODS_KEYWORDS = ['стока', 'стоки', 'продукт', 'хардуер', 'оборудване', 'устройство', 'компютър', 'принтер', 'материал'];

    /** Account code for goods revenue / service revenue. */
    public const GOODS_REVENUE_ACCOUNT = '702';
    public const SERVICE_REVENUE_ACCOUNT = '703';

    /** Fallback expense account. */
    public const DEFAULT_EXPENSE_ACCOUNT = '609';

    /**
     * Expense category name keywords (lower-case, matched with "contains", first matching rule wins) -> account code.
     *
     * @var array<string, string[]>
     */
    public const CATEGORY_ACCOUNT_RULES = [
        '604' => ['заплат', 'трудов', 'възнагражд'],
        '605' => ['осигуров'],
        '621' => ['лихв', 'кредит'],
        '603' => ['амортиз'],
        '601' => ['материал', 'консумат', 'канцелар', 'стоки'],
        '609' => ['банков', 'такса', 'такси', 'глоби', 'данъци'],
        '602' => ['наем', 'офис', 'комунал', 'ток', 'вода', 'интернет', 'телефон', 'софтуер', 'абонамент', 'хостинг', 'външни',
            'услуги', 'маркетинг', 'реклама', 'обучен', 'транспорт', 'командиров', 'консулт', 'счетоводств', 'правни', 'поддръжка'],
    ];

    protected int $companyId;

    /** @var array<string, FinAccount|null> */
    protected array $accountCache = [];

    protected ?Currency $baseCurrency = null;

    protected ?FinEntryImporter $importer = null;

    /** Fields of an entry that follow the document; account, entity and dimensions are never overwritten. */
    protected const SYNCED_FIELDS = ['entry_date', 'amount', 'currency', 'amount_base', 'client_id', 'counterparty_name'];

    /**
     * @param int|null $companyId Defaults to the company of the logged-in user. Pass it explicitly in jobs/console.
     */
    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?: (int)company()->id;
    }

    /**
     * Dry run: what would be transferred, without writing anything.
     *
     * @return array{
     *     invoices: array{candidates:int, new:int, already:int, changed:int, stale:int, total:float, new_total:float, ignored:int},
     *     expenses: array{candidates:int, new:int, already:int, changed:int, stale:int, total:float, new_total:float, ignored:int},
     *     accounts: array<int, array{code:string, name:string, source:string, new:int, already:int, new_total:float}>,
     *     categories: array<int, array{category:string, account_code:string, account_name:string, new:int, already:int}>,
     *     entity: array{id:int|null, name:string|null},
     *     currency: string
     * }
     */
    public function preview(?int $entityId = null): array
    {
        $plan = $this->plan();
        [$existing, $duplicates] = $this->existingEntries();

        $out = [
            'invoices' => $this->blankCounters($plan['ignored']['invoice']),
            'expenses' => $this->blankCounters($plan['ignored']['expense']),
            'accounts' => [],
            'categories' => [],
            'entity' => [],
            'currency' => $this->baseCurrencyCode(),
        ];

        foreach ($plan['rows'] as $row) {
            $bucket = $row['source'] === 'invoice' ? 'invoices' : 'expenses';
            $key = $this->markerKey($row['source'], $row['document_no']);
            $entry = $existing[$key] ?? null;
            unset($existing[$key]);
            $isNew = $entry === null;

            $out[$bucket]['candidates']++;
            $out[$bucket]['total'] += $row['amount_base'];

            $accKey = $row['source'] . ':' . $row['account_code'];
            $out['accounts'][$accKey] ??= [
                'code' => $row['account_code'],
                'name' => $this->accountName($row['account_code']),
                'source' => $row['source'],
                'new' => 0,
                'already' => 0,
                'new_total' => 0.0,
            ];

            if ($isNew) {
                $out[$bucket]['new']++;
                $out[$bucket]['new_total'] += $row['amount_base'];
                $out['accounts'][$accKey]['new']++;
                $out['accounts'][$accKey]['new_total'] += $row['amount_base'];
            } else {
                $out[$bucket][$this->isChanged($entry, $row) ? 'changed' : 'already']++;
                $out['accounts'][$accKey]['already']++;
            }

            if ($row['source'] === 'expense') {
                $catKey = (string)$row['category'];
                $out['categories'][$catKey] ??= [
                    'category' => $row['category'] ?: '-',
                    'account_code' => $row['account_code'],
                    'account_name' => $this->accountName($row['account_code']),
                    'new' => 0,
                    'already' => 0,
                ];
                $out['categories'][$catKey][$isNew ? 'new' : 'already']++;
            }
        }

        // what is left was transferred earlier, but its document no longer qualifies (or is an extra copy) -> removed by run()
        foreach (array_merge(array_values($existing), $duplicates) as $entry) {
            $out[$entry->source === 'invoice' ? 'invoices' : 'expenses']['stale']++;
        }

        foreach (['invoices', 'expenses'] as $bucket) {
            $out[$bucket]['total'] = round($out[$bucket]['total'], 2);
            $out[$bucket]['new_total'] = round($out[$bucket]['new_total'], 2);
        }

        $out['accounts'] = collect($out['accounts'])
            ->map(function ($a) {
                $a['new_total'] = round($a['new_total'], 2);

                return $a;
            })
            ->sortBy(fn($a) => $a['code'])->values()->all();
        $out['categories'] = collect($out['categories'])->sortBy('category')->values()->all();

        $entity = $this->resolveEntity($entityId);
        $out['entity'] = ['id' => $entity?->id, 'name' => $entity?->name];

        return $out;
    }

    /**
     * Transfers all paid invoices and approved expenses that are not transferred yet, updates the entries of documents changed
     * since and removes the entries of documents that no longer qualify. Safe to run repeatedly: unchanged documents already
     * transferred (same marker in document_no) are skipped.
     *
     * @param int|null $entityId Legal entity for the new entries; default = the first active parent legal entity of the company.
     * @param int|null $userId   Stored in fin_entries.created_by.
     * @return array{invoices_created:int, invoices_updated:int, invoices_removed:int, invoices_skipped:int, expenses_created:int, expenses_updated:int, expenses_removed:int, expenses_skipped:int, revenue_total:float, expense_total:float, invoices_ignored:int, expenses_ignored:int, entity_id:int, entity_name:string, currency:string}
     *
     * @throws RuntimeException when there is no legal entity, a required account is missing or another run is in progress
     */
    public function run(?int $entityId = null, ?int $userId = null): array
    {
        $entity = $this->resolveEntity($entityId);

        if (!$entity) {
            throw new RuntimeException(__('databackd-data-sync.errors.no_entity'));
        }

        // two runs at the same time (double click, two tabs) would both see the same document as new and create it twice
        $lock = Cache::lock('databackd-data-sync-' . $this->companyId, 300);

        if (!$lock->get()) {
            throw new RuntimeException(__('databackd-data-sync.errors.running'));
        }

        try {
            return $this->runLocked($entity, $userId);
        } finally {
            $lock->release();
        }
    }

    protected function runLocked(FinEntity $entity, ?int $userId): array
    {
        $plan = $this->plan();
        [$existing, $duplicates] = $this->existingEntries();

        $result = [
            'invoices_created' => 0,
            'invoices_updated' => 0,
            'invoices_removed' => 0,
            'invoices_skipped' => 0,
            'expenses_created' => 0,
            'expenses_updated' => 0,
            'expenses_removed' => 0,
            'expenses_skipped' => 0,
            'revenue_total' => 0.0,
            'expense_total' => 0.0,
            'invoices_ignored' => $plan['ignored']['invoice'],
            'expenses_ignored' => $plan['ignored']['expense'],
            'entity_id' => $entity->id,
            'entity_name' => $entity->name,
            'currency' => $this->baseCurrencyCode(),
        ];

        DB::transaction(function () use ($plan, &$existing, $duplicates, &$result, $entity, $userId) {
            foreach ($plan['rows'] as $row) {
                $prefix = $row['source'] === 'invoice' ? 'invoices' : 'expenses';
                $key = $this->markerKey($row['source'], $row['document_no']);

                if (isset($existing[$key])) {
                    $entry = $existing[$key];
                    unset($existing[$key]);

                    if ($this->isChanged($entry, $row)) {
                        foreach (self::SYNCED_FIELDS as $field) {
                            $entry->{$field} = $row[$field];
                        }

                        $entry->save();
                        $result[$prefix . '_updated']++;
                    } else {
                        $result[$prefix . '_skipped']++;
                    }

                    continue;
                }

                $account = $this->account($row['account_code']);

                if (!$account) {
                    throw new RuntimeException(__('databackd-data-sync.errors.missing_account', ['code' => $row['account_code']]));
                }

                $entry = new FinEntry();
                $entry->company_id = $this->companyId;
                $entry->entity_id = $entity->id;
                $entry->account_id = $account->id;
                $entry->entry_date = $row['entry_date'];
                $entry->kind = 'flow';
                $entry->amount = $row['amount'];
                $entry->currency = $row['currency'];
                $entry->amount_base = $row['amount_base'];
                $entry->client_id = $row['client_id'];
                $entry->counterparty_name = $row['counterparty_name'];
                $entry->document_no = $row['document_no'];
                $entry->description = $row['description'];
                $entry->source = $row['source'];
                $entry->created_by = $userId;
                $entry->save();

                $result[$prefix . '_created']++;

                if ($row['source'] === 'invoice') {
                    $result['revenue_total'] += $row['amount_base'];
                } else {
                    $result['expense_total'] += $row['amount_base'];
                }
            }

            // transferred earlier, but the document was deleted or no longer qualifies (cancelled, unpaid again, rejected ...),
            // plus extra entries with the same marker, so that every document ends with exactly one entry
            foreach (array_merge(array_values($existing), $duplicates) as $entry) {
                $entry->delete();
                $result[($entry->source === 'invoice' ? 'invoices' : 'expenses') . '_removed']++;
            }
        });

        $result['revenue_total'] = round($result['revenue_total'], 2);
        $result['expense_total'] = round($result['expense_total'], 2);

        return $result;
    }

    /**
     * The expense account code for a category name (keyword rules, default 609).
     */
    public static function accountCodeForCategory(?string $categoryName): string
    {
        $name = mb_strtolower((string)$categoryName);

        if ($name !== '') {
            foreach (self::CATEGORY_ACCOUNT_RULES as $code => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($name, $keyword)) {
                        return (string)$code;
                    }
                }
            }
        }

        return self::DEFAULT_EXPENSE_ACCOUNT;
    }

    /**
     * Stable idempotency marker of an invoice.
     */
    public static function invoiceDocumentNo(Invoice $invoice): string
    {
        $number = trim((string)($invoice->invoice_number ?: $invoice->id));

        if (str_starts_with(strtoupper($number), 'INV')) {
            return Str::limit(str_replace('#', '-', $number), 60, '');
        }

        return Str::limit('INV-' . $number, 60, '');
    }

    /**
     * Stable idempotency marker of an expense.
     */
    public static function expenseDocumentNo(Expense $expense): string
    {
        return 'EXP-' . $expense->id;
    }

    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Builds the list of candidate entries (both new and already transferred) and the number of ignored documents.
     *
     * @return array{rows: array<int, array<string, mixed>>, ignored: array{invoice:int, expense:int}}
     */
    protected function plan(): array
    {
        $rows = [];
        $ignored = ['invoice' => 0, 'expense' => 0];

        // ---- invoices
        $invoices = Invoice::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('status', 'paid')
            ->with(['items', 'currency', 'client', 'clientdetails'])
            ->orderBy('issue_date')->orderBy('id')
            ->get();

        $paid = Payment::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('status', 'complete')
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->selectRaw('invoice_id, SUM(amount) as paid')
            ->groupBy('invoice_id')
            ->pluck('paid', 'invoice_id');

        $credited = $this->creditedNetAmounts($invoices->pluck('id')->all());
        $markers = [];

        foreach ($invoices as $invoice) {
            $amount = round($this->invoiceNetAmount($invoice) - ($credited[$invoice->id] ?? 0), 2);

            if ((float)($paid[$invoice->id] ?? 0) <= 0 || $amount <= 0 || !$invoice->issue_date) {
                $ignored['invoice']++;

                continue;
            }

            $currency = $this->currencyCode($invoice->currency?->currency_code);
            $number = $invoice->invoice_number ?: (string)$invoice->id;
            $date = $invoice->issue_date->format('Y-m-d');
            $marker = self::invoiceDocumentNo($invoice);

            // two invoices with the same number (edited by hand): the second one must not be taken for the first
            if (isset($markers[$marker])) {
                $marker = Str::limit($marker, 50, '') . '-' . $invoice->id;
            }

            $markers[$marker] = true;
            // the counterparty is the client's company (as in the other sources), the contact person only without one
            $counterparty = $invoice->clientdetails?->company_name ?: $invoice->client?->name;

            $rows[] = [
                'source' => 'invoice',
                'document_no' => $marker,
                'entry_date' => $date,
                'account_code' => $this->invoiceAccountCode($invoice, $amount),
                'amount' => $amount,
                'currency' => $currency,
                'amount_base' => $this->toBase($amount, $currency, $date, $invoice->exchange_rate, $invoice->default_currency_id),
                'client_id' => $invoice->client_id,
                'counterparty_name' => $counterparty ? Str::limit((string)$counterparty, 191, '') : null,
                'description' => Str::limit(__('databackd-data-sync.entry.invoice', ['number' => $number]), 191, ''),
                'category' => null,
            ];
        }

        // ---- expenses
        $expenses = Expense::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('status', 'approved')
            ->with(['currency', 'category'])
            ->orderBy('purchase_date')->orderBy('id')
            ->get();

        foreach ($expenses as $expense) {
            $amount = round((float)$expense->price, 2);

            if ($amount <= 0 || !$expense->purchase_date) {
                $ignored['expense']++;

                continue;
            }

            $currency = $this->currencyCode($expense->currency?->currency_code);
            $categoryName = $expense->category?->category_name;
            $date = $expense->purchase_date->format('Y-m-d');

            $rows[] = [
                'source' => 'expense',
                'document_no' => self::expenseDocumentNo($expense),
                'entry_date' => $date,
                'account_code' => self::accountCodeForCategory($categoryName),
                'amount' => $amount,
                'currency' => $currency,
                'amount_base' => $this->toBase($amount, $currency, $date, $expense->exchange_rate, $expense->default_currency_id),
                'client_id' => null,
                'counterparty_name' => $expense->purchase_from ? Str::limit($expense->purchase_from, 191, '') : null,
                'description' => Str::limit(__('databackd-data-sync.entry.expense', ['id' => $expense->id, 'name' => $expense->item_name]), 191, ''),
                'category' => $categoryName,
            ];
        }

        return ['rows' => $rows, 'ignored' => $ignored];
    }

    /**
     * Net invoice value: sub total minus discount (fixed or percent); the invoice total when that is not positive.
     */
    protected function invoiceNetAmount(Invoice $invoice): float
    {
        $sub = (float)$invoice->sub_total;
        $discount = (float)$invoice->discount;
        $discount = ($invoice->discount_type === 'percent') ? $sub * $discount / 100 : $discount;
        $net = round($sub - $discount, 2);

        return $net > 0 ? $net : round((float)$invoice->total, 2);
    }

    /**
     * Net value (sub total minus discount) of the credit notes per invoice id.
     *
     * @param int[] $invoiceIds
     * @return array<int, float>
     */
    protected function creditedNetAmounts(array $invoiceIds): array
    {
        if (!$invoiceIds) {
            return [];
        }

        $out = [];

        CreditNotes::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->whereIn('invoice_id', $invoiceIds)
            ->whereNull('deleted_at')
            ->get(['invoice_id', 'sub_total', 'discount', 'discount_type'])
            ->each(function ($note) use (&$out) {
                $sub = (float)$note->sub_total;
                $discount = $note->discount_type === 'percent' ? $sub * (float)$note->discount / 100 : (float)$note->discount;
                $out[$note->invoice_id] = ($out[$note->invoice_id] ?? 0) + max(0, $sub - $discount);
            });

        return $out;
    }

    /**
     * 702 when goods are at least half of the net value, otherwise 703.
     */
    protected function invoiceAccountCode(Invoice $invoice, float $net): string
    {
        $goods = 0.0;

        foreach ($invoice->items as $item) {
            $name = mb_strtolower((string)$item->item_name);

            foreach (self::GOODS_KEYWORDS as $keyword) {
                if (str_contains($name, $keyword)) {
                    $goods += (float)$item->amount;

                    break;
                }
            }
        }

        $itemsTotal = (float)$invoice->items->sum('amount');
        $base = $itemsTotal > 0 ? $itemsTotal : $net;

        return ($goods > 0 && $goods * 2 >= $base) ? self::GOODS_REVENUE_ACCOUNT : self::SERVICE_REVENUE_ACCOUNT;
    }

    protected function currencyCode(?string $code): string
    {
        $code = strtoupper(trim((string)$code));

        return strlen($code) === 3 ? $code : $this->baseCurrencyCode();
    }

    protected function baseCurrencyRow(): ?Currency
    {
        if ($this->baseCurrency === null) {
            $currencyId = Company::where('id', $this->companyId)->value('currency_id');
            $this->baseCurrency = $currencyId ? Currency::withoutGlobalScopes()->find($currencyId) : null;
        }

        return $this->baseCurrency;
    }

    protected function baseCurrencyCode(): string
    {
        $code = strtoupper(trim((string)$this->baseCurrencyRow()?->currency_code));

        return strlen($code) === 3 ? $code : 'EUR';
    }

    /**
     * Amount in the company base currency (see the class description for the order of the rates).
     *
     * @param mixed $documentRate      exchange_rate of the document: units of the base currency for 1 unit of the document currency
     * @param mixed $documentBaseId    default_currency_id of the document: the base currency the rate was recorded against
     */
    protected function toBase(float $amount, string $currency, string $date, $documentRate, $documentBaseId): float
    {
        $base = $this->baseCurrencyCode();

        if ($currency === $base) {
            return round($amount, 2);
        }

        $this->importer ??= new FinEntryImporter($this->companyId);

        // BGN <-> EUR is the fixed legal rate: a BGN document from before 2026 carries a rate against BGN (1.0), not against EUR
        if (in_array($currency, ['BGN', 'EUR'], true) && in_array($base, ['BGN', 'EUR'], true)) {
            return $this->importer->toBase($amount, $currency, $date);
        }

        if ((float)$documentRate > 0 && (!$documentBaseId || (int)$documentBaseId === (int)$this->baseCurrencyRow()?->id)) {
            return round($amount * (float)$documentRate, 2);
        }

        return $this->importer->toBase($amount, $currency, $date);
    }

    /**
     * Everything already transferred for the company: the first entry per marker keyed by markerKey(), and the extra entries
     * with an already seen marker.
     *
     * @return array{0: array<string, FinEntry>, 1: FinEntry[]}
     */
    protected function existingEntries(): array
    {
        $out = [];
        $duplicates = [];

        FinEntry::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->whereIn('source', self::SOURCES)
            ->whereNotNull('document_no')
            ->orderBy('id')
            ->get()
            ->each(function (FinEntry $entry) use (&$out, &$duplicates) {
                $key = $this->markerKey($entry->source, $entry->document_no);

                if (isset($out[$key])) {
                    $duplicates[] = $entry;
                } else {
                    $out[$key] = $entry;
                }
            });

        return [$out, $duplicates];
    }

    protected function markerKey(string $source, string $documentNo): string
    {
        return $source . '|' . $documentNo;
    }

    /**
     * Did the document change since its entry was written (only the fields that follow the document)?
     */
    protected function isChanged(FinEntry $entry, array $row): bool
    {
        $date = $entry->entry_date instanceof \DateTimeInterface ? $entry->entry_date->format('Y-m-d') : (string)$entry->entry_date;

        return $date !== $row['entry_date']
            || abs((float)$entry->amount - (float)$row['amount']) >= 0.005
            || abs((float)$entry->amount_base - (float)$row['amount_base']) >= 0.005
            || strtoupper((string)$entry->currency) !== $row['currency']
            || (int)$entry->client_id !== (int)$row['client_id']
            || (string)$entry->counterparty_name !== (string)$row['counterparty_name'];
    }

    protected function resolveEntity(?int $entityId): ?FinEntity
    {
        $query = FinEntity::withoutGlobalScopes()->where('company_id', $this->companyId);

        if ($entityId) {
            return $query->where('id', $entityId)->first();
        }

        return $query->where('is_active', 1)->whereNull('parent_id')->orderBy('sort')->orderBy('id')->first()
            ?: FinEntity::withoutGlobalScopes()->where('company_id', $this->companyId)->where('is_active', 1)->orderBy('sort')->orderBy('id')->first();
    }

    protected function account(string $code): ?FinAccount
    {
        if (!array_key_exists($code, $this->accountCache)) {
            $this->accountCache[$code] = FinAccount::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->where('code', $code)
                ->first();
        }

        return $this->accountCache[$code];
    }

    protected function accountName(string $code): string
    {
        return $this->account($code)?->name ?? $code;
    }

    /**
     * @return array{candidates:int, new:int, already:int, changed:int, stale:int, total:float, new_total:float, ignored:int}
     */
    protected function blankCounters(int $ignored): array
    {
        return ['candidates' => 0, 'new' => 0, 'already' => 0, 'changed' => 0, 'stale' => 0, 'total' => 0.0, 'new_total' => 0.0, 'ignored' => $ignored];
    }

}
