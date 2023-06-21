<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinDimension;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk entry grid: one row per account for a legal entity + month, saved with one AJAX call.
 * The grid owns only manual entries dated on the last day of the month (source = manual) - imported
 * entries are never changed, they are shown as read-only information.
 */
class FinEntryBulkController extends AccountBaseController
{

    const DIMENSION_COLUMNS = [
        'region' => 'region_id',
        'client_segment' => 'client_segment_id',
        'product_group' => 'product_group_id',
        'cost_center' => 'cost_center_id',
    ];

    const GROUP_ORDER = ['revenue', 'expense', 'asset', 'liability', 'equity'];

    /** Upper limit of rows in one save (a full chart of accounts with several breakdown rows each fits easily). */
    const MAX_ROWS = 3000;
    const BALANCE_TYPES = ['asset', 'liability', 'equity'];

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-bulk.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index()
    {
        $this->entities = FinEntity::where('is_active', 1)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency']);

        $dimensions = FinDimension::orderBy('name')->get(['id', 'type', 'name'])
            ->groupBy('type')
            ->map(fn($rows) => $rows->map(fn($d) => ['id' => $d->id, 'name' => $d->name])->values())
            ->all();

        foreach (array_keys(self::DIMENSION_COLUMNS) as $type) {
            $dimensions[$type] = $dimensions[$type] ?? [];
        }

        $this->dimensions = $dimensions;

        $this->defaultMonth = now()->format('Y-m');
        $this->entriesUrl = \Route::has('databackd.data.entries.index') ? route('databackd.data.entries.index') : null;

        return view('databackd.data.bulk.index', $this->data);
    }

    /**
     * JSON with the accounts grouped for the grid, the manual values already entered for
     * entity + month (+ chosen breakdown) and read-only information about other sources.
     */
    public function load(Request $request): JsonResponse
    {
        [$entity, $month, $dimType] = $this->context($request);

        if (!$entity) {
            return response()->json(Reply::error('databackd-data-bulk.messages.entity_invalid'));
        }

        if (!$month) {
            return response()->json(Reply::error('databackd-data-bulk.messages.month_invalid'));
        }

        $end = Carbon::createFromFormat('Y-m-d', $month . '-01')->endOfMonth();
        $start = $end->copy()->startOfMonth();

        $accounts = FinAccount::where('is_active', 1)->orderBy('code')->get();
        $contraMap = FinAccount::contraMapFor((int)company()->id); // analytic accounts of a contra account (2411 under 241) are contra too

        $entries = FinEntry::where('entity_id', $entity->id)
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->get()
            ->groupBy('account_id');

        $groups = [];
        $managedCurrencies = [];

        foreach (self::GROUP_ORDER as $type) {
            $rows = [];

            foreach ($accounts->where('type', $type) as $account) {
                $accountEntries = $entries->get($account->id, collect());
                $managed = $accountEntries->filter(fn($e) => $this->isManaged($e, $dimType, $end->toDateString()));
                $others = $accountEntries->reject(fn($e) => $this->isManaged($e, $dimType, $end->toDateString()));

                foreach ($managed as $entry) {
                    $managedCurrencies[strtoupper((string)$entry->currency)] = true;
                }

                $rows[] = [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'contra' => $contraMap[$account->id] ?? false,
                    'rows' => $managed->sortBy('id')->map(fn($e) => [
                        'entry_id' => $e->id,
                        'amount' => (float)$e->amount,
                        'currency' => strtoupper((string)$e->currency),
                        'dim_id' => $dimType === 'none' ? null : $e->{self::DIMENSION_COLUMNS[$dimType]},
                        'description' => $e->description,
                    ])->values()->all(),
                    'others' => $this->otherSources($others),
                ];
            }

            if ($rows) {
                $groups[] = ['key' => $type, 'accounts' => $rows];
            }
        }

        $mixed = $this->mixedCurrencies(array_keys($managedCurrencies));

        return response()->json([
            'status' => 'success',
            'entity_id' => $entity->id,
            'currency' => $this->gridCurrency($entity, array_keys($managedCurrencies)),
            // S12 review A: a month whose grid entries are in more than one currency is shown read-only (see store())
            'mixed_currencies' => $mixed,
            'mixed_message' => $mixed ? $this->mixedMessage($mixed) : null,
            'entries_url' => $mixed ? $this->entriesUrl($entity, $end) : null,
            'month' => $month,
            'dim_type' => $dimType,
            'date' => $end->toDateString(),
            'groups' => $groups,
        ]);
    }

    /**
     * Upsert the manual month-end entries. An empty amount deletes the existing manual entry of the row.
     * Nothing is written when any row is invalid.
     */
    public function store(Request $request): JsonResponse
    {
        [$entity, $month, $dimType] = $this->context($request);

        if (!$entity) {
            return response()->json(Reply::error('databackd-data-bulk.messages.entity_invalid'));
        }

        if (!$month) {
            return response()->json(Reply::error('databackd-data-bulk.messages.month_invalid'));
        }

        // S12/A07: the grid posts its rows as one JSON field - as form fields a long chart of accounts (5 fields per row)
        // went over PHP max_input_vars (1000) and the rows after the limit were silently dropped
        $input = $request->filled('rows_json') ? json_decode((string)$request->input('rows_json'), true) : $request->input('rows', []);

        if (!is_array($input) || !$input) {
            return response()->json(Reply::error('databackd-data-bulk.messages.no_rows'));
        }

        if (count($input) > self::MAX_ROWS) {
            return response()->json(Reply::error(__('databackd-data-bulk.messages.too_many_rows', ['max' => self::MAX_ROWS])));
        }

        $end = Carbon::createFromFormat('Y-m-d', $month . '-01')->endOfMonth();
        $endDate = $end->toDateString();
        $dimColumn = $dimType === 'none' ? null : self::DIMENSION_COLUMNS[$dimType];

        $accounts = FinAccount::where('is_active', 1)->get()->keyBy('id');
        $dimensionIds = $dimColumn ? FinDimension::where('type', $dimType)->pluck('id')->all() : [];

        // Manual entries of the grid for this entity + month (the only records it may change)
        $managed = FinEntry::where('entity_id', $entity->id)
            ->where('source', 'manual')
            ->whereDate('entry_date', $endDate)
            ->whereNull('counterparty_name')->whereNull('document_no')->whereNull('client_id')
            ->get()
            ->filter(fn($e) => $this->isManaged($e, $dimType, $endDate))
            ->keyBy('id');

        $byKey = [];

        foreach ($managed as $entry) {
            $byKey[$entry->account_id . '|' . ($dimColumn ? (int)$entry->{$dimColumn} : 0)] = $entry;
        }

        $invalid = [];
        $plan = [];
        $seenKeys = [];
        $usedEntryIds = [];

        foreach ($input as $index => $row) {
            $row = is_array($row) ? $row : [];
            $accountId = (int)($row['account_id'] ?? 0);
            $account = $accounts->get($accountId);

            if (!$account) {
                $invalid[] = ['index' => (int)$index, 'message' => __('databackd-data-bulk.messages.account_invalid')];
                continue;
            }

            $dimId = null;

            if ($dimColumn) {
                $dimId = ($row['dim_id'] ?? '') === '' || ($row['dim_id'] ?? null) === null ? null : (int)$row['dim_id'];

                if ($dimId !== null && !in_array($dimId, $dimensionIds, true)) {
                    $invalid[] = ['index' => (int)$index, 'message' => __('databackd-data-bulk.messages.dimension_invalid')];
                    continue;
                }
            }

            $key = $accountId . '|' . (int)$dimId;
            $rawAmount = trim((string)($row['amount'] ?? ''));
            $amount = null;

            if ($rawAmount !== '') {
                $amount = $this->parseAmount($rawAmount);

                if ($amount === null || $amount <= 0) {
                    $invalid[] = ['index' => (int)$index, 'message' => __('databackd-data-bulk.messages.invalid_amount')];
                    continue;
                }

                if (isset($seenKeys[$key])) {
                    $invalid[] = ['index' => (int)$index, 'message' => __('databackd-data-bulk.messages.duplicate_row')];
                    continue;
                }

                $seenKeys[$key] = true;
            }

            // Resolve the existing record: by id first (row may have changed its breakdown), then by key
            $existing = null;
            $entryId = (int)($row['entry_id'] ?? 0);

            if ($entryId && $managed->has($entryId)) {
                $existing = $managed->get($entryId);
            }
            elseif (isset($byKey[$key])) {
                $existing = $byKey[$key];
            }

            if ($existing && isset($usedEntryIds[$existing->id])) {
                $existing = null;
            }

            if ($existing) {
                $usedEntryIds[$existing->id] = true;
            }

            $description = trim((string)($row['description'] ?? ''));

            $plan[] = [
                'account' => $account,
                'dim_id' => $dimId,
                'amount' => $amount,
                'description' => $description === '' ? null : mb_substr($description, 0, 500),
                'existing' => $existing,
            ];
        }

        if ($invalid) {
            return response()->json(Reply::error(
                __('databackd-data-bulk.messages.invalid_rows_count', ['count' => count($invalid)]),
                'invalid_rows',
                ['invalid' => $invalid]
            ));
        }

        $managedCurrencies = $managed->pluck('currency')->all();

        // S12 review A: the grid has one currency for all its rows. With grid entries in several currencies of the month
        // (e.g. a BGN entry from the single form in an EUR entity) every posted row used to get the entity's currency -
        // 1955,83 BGN silently became 1955,83 EUR. Such a month is not edited here; the entries keep their currency.
        if ($mixed = $this->mixedCurrencies($managedCurrencies)) {
            return response()->json(Reply::error($this->mixedMessage($mixed)));
        }

        $currency = $this->gridCurrency($entity, $managedCurrencies);

        // the grid was loaded with another currency than the month has now (entries added/changed elsewhere meanwhile):
        // its amounts were typed for that currency, so they are not saved under a different one
        $shownCurrency = strtoupper(trim((string)$request->input('currency', '')));

        if ($shownCurrency !== '' && $shownCurrency !== $currency) {
            return response()->json(Reply::error(__('databackd-data-bulk.messages.currency_changed', ['shown' => $shownCurrency, 'currency' => $currency])));
        }

        // a currency without a rate on the month end would be taken 1:1 into the base currency (silently wrong reports)
        if ($plan && collect($plan)->contains(fn($item) => $item['amount'] !== null) && !FinEntryController::hasRate($currency, $endDate)) {
            return response()->json(Reply::error(__('databackd-data-entries.messages.no_rate', [
                'currency' => $currency,
                'base' => FinEntryController::baseCurrency(),
                'date' => $end->format(company()->date_format),
            ])));
        }
        $created = $updated = $deleted = 0;

        DB::transaction(function () use ($plan, $entity, $endDate, $dimColumn, $currency, &$created, &$updated, &$deleted) {
            foreach ($plan as $item) {
                $existing = $item['existing'];

                if ($item['amount'] === null) {
                    if ($existing) {
                        $existing->delete();
                        $deleted++;
                    }

                    continue;
                }

                $entry = $existing ?: new FinEntry();

                if (!$existing) {
                    $entry->company_id = company()->id;
                    $entry->entity_id = $entity->id;
                    $entry->account_id = $item['account']->id;
                    $entry->source = 'manual';
                    $entry->created_by = user()->id;
                }

                $entry->entry_date = $endDate;
                $entry->kind = in_array($item['account']->type, self::BALANCE_TYPES, true) ? 'balance' : 'flow';
                $entry->amount = $item['amount'];
                $entry->currency = $currency;
                $entry->amount_base = FinEntryController::convertToBase($item['amount'], $currency, $endDate);
                $entry->description = $item['description'];

                foreach (self::DIMENSION_COLUMNS as $column) {
                    $entry->{$column} = $column === $dimColumn ? $item['dim_id'] : null;
                }

                $changed = $existing && $entry->isDirty();
                $entry->save();

                if (!$existing) {
                    $created++;
                }
                elseif ($changed) {
                    $updated++;
                }
            }
        });

        $message = ($created + $updated + $deleted) === 0
            ? __('databackd-data-bulk.messages.nothing_changed')
            : __('databackd-data-bulk.messages.saved', ['created' => $created, 'updated' => $updated, 'deleted' => $deleted]);

        return response()->json(Reply::successWithData($message, ['created' => $created, 'updated' => $updated, 'deleted' => $deleted]));
    }

    /**
     * @return array{0: FinEntity|null, 1: string|null, 2: string} entity, month (Y-m), breakdown type (none|region|...)
     */
    private function context(Request $request): array
    {
        $entityId = (int)$request->input('entity_id');
        $entity = $entityId
            ? FinEntity::where('is_active', 1)->find($entityId)
            : FinEntity::where('is_active', 1)->orderBy('sort')->orderBy('name')->first();

        $month = (string)$request->input('month', now()->format('Y-m'));
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : null;

        $dimType = (string)$request->input('dim_type', 'none');
        $dimType = isset(self::DIMENSION_COLUMNS[$dimType]) ? $dimType : 'none';

        return [$entity, $month, $dimType];
    }

    /**
     * An entry the grid can edit: manual, dated on the last day of the month, without counterparty/document/client
     * and with no breakdown other than the chosen one.
     */
    private function isManaged(FinEntry $entry, string $dimType, string $endDate): bool
    {
        if ($entry->source !== 'manual' || $entry->entry_date->toDateString() !== $endDate) {
            return false;
        }

        if ($entry->counterparty_name !== null || $entry->document_no !== null || $entry->client_id !== null) {
            return false;
        }

        foreach (self::DIMENSION_COLUMNS as $type => $column) {
            if ($type !== $dimType && $entry->{$column} !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read-only summary per source for entries the grid does not manage. Balances: only the latest date per source.
     */
    private function otherSources(Collection $entries): array
    {
        $result = [];

        foreach ($entries->groupBy('source') as $source => $rows) {
            if ($rows->first()->kind === 'balance') {
                $latest = $rows->max(fn($e) => $e->entry_date->toDateString());
                $rows = $rows->filter(fn($e) => $e->entry_date->toDateString() === $latest);
            }

            $result[] = [
                'source' => $source,
                'label' => __('databackd-data-common.sources.' . $source),
                'amount' => round((float)$rows->sum('amount'), 2),
                'count' => $rows->count(),
            ];
        }

        return $result;
    }

    /**
     * Accepts "1234.56", "1234,56", "1 234,56", "1.234,56", "1,234.56". Returns null when not a number.
     */
    private function parseAmount(string $value): ?float
    {
        $s = preg_replace('/[\s\x{00A0}\x{202F}]|€|EUR|eur|лв\.?/u', '', trim($value));

        if ($s === '' || !preg_match('/^[+-]?[\d.,]+$/', $s)) {
            return null;
        }

        $negative = str_starts_with($s, '-');
        $s = ltrim($s, '+-');

        $comma = strrpos($s, ',');
        $dot = strrpos($s, '.');

        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $s = str_replace($decimal, '#', str_replace($thousands, '', $s));
            $s = str_replace('#', '.', $s);
        }
        elseif ($comma !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }
        elseif ($dot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }

        if (!is_numeric($s)) {
            return null;
        }

        $number = round((float)$s, 2);

        if ($number >= 1e12) {
            return null;
        }

        return $negative ? -$number : $number;
    }

    /**
     * Currency of the grid: the legal entity's currency, unless the month already holds grid entries that all share
     * another one (e.g. BGN months entered before the entity switched to EUR) - they are shown and saved in their own
     * currency instead of being relabelled. Mixed currencies: the entity's currency, only as the label of the read-only
     * grid - such a month cannot be saved here (mixedCurrencies()).
     */
    private function gridCurrency(FinEntity $entity, array $existingCurrencies): string
    {
        $currency = $entity->currency ?: FinEntryController::baseCurrency();
        $existingCurrencies = $this->distinctCurrencies($existingCurrencies);

        return count($existingCurrencies) === 1 ? $existingCurrencies[0] : strtoupper($currency);
    }

    /**
     * S12 review A: the currencies of the grid entries when there is more than one (sorted), else an empty array.
     * The grid shows and saves all rows in one currency, so such a month is read-only here and is edited in the
     * entries list, where every entry keeps its own currency.
     */
    private function mixedCurrencies(array $existingCurrencies): array
    {
        $currencies = $this->distinctCurrencies($existingCurrencies);
        sort($currencies);

        return count($currencies) > 1 ? $currencies : [];
    }

    private function distinctCurrencies(array $currencies): array
    {
        return array_values(array_unique(array_filter(array_map(fn($c) => strtoupper(trim((string)$c)), $currencies))));
    }

    private function mixedMessage(array $currencies): string
    {
        return __('databackd-data-bulk.messages.mixed_currencies', ['currencies' => implode(', ', $currencies)]);
    }

    /** The entries list filtered to the legal entity and the month end (the date of the grid entries). */
    private function entriesUrl(FinEntity $entity, Carbon $end): ?string
    {
        if (!\Route::has('databackd.data.entries.index')) {
            return null;
        }

        return route('databackd.data.entries.index', ['entity_id' => $entity->id, 'from' => $end->toDateString(), 'to' => $end->toDateString()]);
    }

}
