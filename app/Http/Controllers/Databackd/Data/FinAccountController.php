<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Data\FinAccount\SaveFinAccountRequest;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinEntry;
use App\Services\Databackd\ChartOfAccounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts (reference data): tree by NSS group, create / edit / deactivate / delete.
 */
class FinAccountController extends AccountBaseController
{

    const SUBTYPES = [
        'current_asset', 'non_current_asset', 'cash', 'receivable', 'inventory',
        'current_liability', 'non_current_liability', 'cogs', 'payroll', 'opex',
        'depreciation', 'interest', 'tax', 'other',
    ];

    /**
     * S12/A07: subtypes that make sense for each type - the reports read the subtype (cash, receivable, cogs ...) only
     * within the type, so e.g. "cash" on a revenue account was silently ignored.
     */
    const SUBTYPES_BY_TYPE = [
        'asset' => ['current_asset', 'non_current_asset', 'cash', 'receivable', 'inventory', 'other'],
        'liability' => ['current_liability', 'non_current_liability', 'tax', 'other'],
        'equity' => ['other'],
        'revenue' => ['interest', 'other'],
        'expense' => ['cogs', 'payroll', 'opex', 'depreciation', 'interest', 'tax', 'other'],
    ];

    /** Suggested account type by the first digit of the code (used as a hint in the form). */
    const TYPE_BY_GROUP = ['1' => 'equity', '2' => 'asset', '3' => 'asset', '4' => 'asset', '5' => 'asset', '6' => 'expense', '7' => 'revenue'];

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-accounts.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index()
    {
        $accounts = FinAccount::orderBy('code')->get();

        // Entry counts per account: one grouped query instead of N+1
        $counts = FinEntry::query()
            ->selectRaw('account_id, COUNT(*) as aggregate')
            ->groupBy('account_id')
            ->pluck('aggregate', 'account_id');

        $this->groups = $this->buildGroups($accounts, $counts);
        $this->totalAccounts = $accounts->count();
        $this->activeAccounts = $accounts->where('is_active', true)->count();
        $this->accountsWithEntries = $accounts->filter(fn($a) => ($counts[$a->id] ?? 0) > 0)->count();
        $this->totalEntries = (int)$counts->sum();
        $this->contraCodes = FinAccount::CONTRA_CODES;
        $this->contraIds = FinAccount::contraMap($accounts); // review A: 2411 under 241 is marked as contra too
        $this->entriesRoute = \Route::has('databackd.data.entries.index') ? 'databackd.data.entries.index' : null;
        $this->standardCodes = self::standardCodes();
        // the standard NSS chart (full / micro template) is applied from step 3 of the setup wizard
        // (same package module as this page: databackd_data)
        // audit 2026-10-30 (G11): the setup wizard is admin-only (OnboardingController)
        $this->chartTemplateUrl = \Route::has('databackd.gaps.onboarding.index') && in_array('admin', user_roles())
            ? route('databackd.gaps.onboarding.index', ['step' => 3]) : null;

        return view('databackd.data.accounts.index', $this->data);
    }

    public function create()
    {
        $this->account = new FinAccount(['type' => 'asset', 'is_active' => true]);
        $this->entriesCount = 0;
        $this->parents = $this->parentOptions(null);

        return $this->formResponse(__('databackd-data-accounts.add_account'));
    }

    public function edit($id)
    {
        $this->account = FinAccount::findOrFail($id);
        $this->entriesCount = $this->account->entries()->count();
        $this->parents = $this->parentOptions($this->account);

        return $this->formResponse(__('databackd-data-accounts.edit_account'));
    }

    public function store(SaveFinAccountRequest $request)
    {
        $account = new FinAccount();
        $account->company_id = company()->id;
        $this->fill($account, $request);
        $account->save();

        return Reply::success(__('databackd-data-accounts.messages.created'));
    }

    public function update(SaveFinAccountRequest $request, $id)
    {
        $account = FinAccount::findOrFail($id);

        if ($request->type !== $account->type && $account->entries()->exists()) {
            return Reply::error(__('databackd-data-accounts.messages.type_locked'));
        }

        $parentId = $request->filled('parent_id') ? (int)$request->parent_id : null;

        if ($parentId !== null) {
            if ($parentId === $account->id) {
                return Reply::error(__('databackd-data-accounts.validation.parent_self'));
            }

            if (in_array($parentId, $this->descendantIds($account), true)) {
                return Reply::error(__('databackd-data-accounts.validation.parent_descendant'));
            }
        }

        $this->fill($account, $request);
        $account->save();

        return Reply::success(__('databackd-data-accounts.messages.updated'));
    }

    /** Activate / deactivate an account (it is kept for history, only hidden from data entry). */
    public function toggle($id)
    {
        $account = FinAccount::findOrFail($id);
        $account->is_active = !$account->is_active;
        $account->save();

        return Reply::success(__($account->is_active
            ? 'databackd-data-accounts.messages.activated'
            : 'databackd-data-accounts.messages.deactivated'));
    }

    /**
     * Only accounts that nothing refers to can be deleted. S12/A07: also not the standard NSS accounts - the chart is
     * re-created on every report (ChartOfAccounts::ensureFor), the sync and the statutory reports use their codes; they
     * are deactivated instead. Budget lines and counterparty rules that point to the account block the delete as well.
     */
    public function destroy($id)
    {
        $account = FinAccount::findOrFail($id);

        if (in_array($account->code, self::standardCodes(), true)) {
            return Reply::error(__('databackd-data-accounts.messages.standard_delete'));
        }

        if ($account->entries()->exists()) {
            return Reply::error(__('databackd-data-accounts.messages.has_entries'));
        }

        if (FinAccount::where('parent_id', $account->id)->exists()) {
            return Reply::error(__('databackd-data-accounts.messages.has_children'));
        }

        if ($usage = self::otherUsage($account->id)) {
            return Reply::error(__('databackd-data-accounts.messages.in_use', ['list' => $usage]));
        }

        $account->delete();

        return Reply::success(__('databackd-data-accounts.messages.deleted'));
    }

    // ------------------------------------------------------------------

    /** @return string[] codes of the standard NSS chart (ChartOfAccounts::ACCOUNTS) */
    public static function standardCodes(): array
    {
        return array_map('strval', array_keys(ChartOfAccounts::ACCOUNTS));
    }

    /**
     * Budget lines / counterparty rules that refer to the account, as a readable list ("" = none).
     */
    private static function otherUsage(int $accountId): string
    {
        $companyId = company()->id;
        $usage = [];

        $checks = [
            'budget_lines' => fn() => Schema::hasTable('fin_budget_lines')
                ? DB::table('fin_budget_lines')->where('company_id', $companyId)->where('account_id', $accountId)->count() : 0,
            'counterparty_rules' => fn() => Schema::hasTable('fin_counterparty_mappings')
                ? DB::table('fin_counterparty_mappings')->where('company_id', $companyId)->where('account_id', $accountId)->count() : 0,
        ];

        foreach ($checks as $key => $count) {
            if (($n = $count()) > 0) {
                $usage[] = __('databackd-data-accounts.usage.' . $key, ['count' => $n]);
            }
        }

        return implode(', ', $usage);
    }

    private function formResponse(string $title)
    {
        $this->pageTitle = $title;
        $this->subtypes = self::SUBTYPES;
        $this->subtypesByType = self::SUBTYPES_BY_TYPE;
        $this->isStandard = $this->account->exists && in_array($this->account->code, self::standardCodes(), true);
        $this->typeByGroup = self::TYPE_BY_GROUP;
        $this->view = 'databackd.data.accounts.form';

        if (request()->ajax()) {
            return $this->returnAjax($this->view);
        }

        return view('databackd.data.accounts.show', $this->data);
    }

    private function fill(FinAccount $account, SaveFinAccountRequest $request): void
    {
        $account->code = trim($request->code);
        $account->name = trim($request->name);
        $account->type = $request->type;
        $account->subtype = $request->filled('subtype') ? $request->subtype : null;
        $account->parent_id = $request->filled('parent_id') ? (int)$request->parent_id : null;
        $account->is_active = $request->boolean('is_active');
    }

    /** Accounts that may be chosen as parent: everything except the account itself and its descendants. */
    private function parentOptions(?FinAccount $account): Collection
    {
        $excluded = $account ? array_merge([$account->id], $this->descendantIds($account)) : [];

        return FinAccount::orderBy('code')->get(['id', 'code', 'name'])
            ->reject(fn($a) => in_array($a->id, $excluded, true))
            ->values();
    }

    /** @return int[] */
    private function descendantIds(FinAccount $account): array
    {
        $byParent = FinAccount::whereNotNull('parent_id')->get(['id', 'parent_id'])->groupBy('parent_id');
        $result = [];
        $queue = [$account->id];

        while ($queue) {
            $current = array_shift($queue);
            foreach ($byParent->get($current, []) as $child) {
                if (!in_array($child->id, $result, true)) {
                    $result[] = $child->id;
                    $queue[] = $child->id;
                }
            }
        }

        return $result;
    }

    /**
     * Group accounts by the first digit of the code and flatten each group into display rows
     * (parents first, sub-accounts indented by `depth`).
     *
     * @return array<int, array{key:string, accounts:int, with_entries:int, entries:int, rows:array}>
     */
    private function buildGroups(Collection $accounts, Collection $counts): array
    {
        $groups = [];
        $byGroup = $accounts->groupBy(function ($a) {
            $first = substr($a->code, 0, 1);

            return ctype_digit($first) && $first >= '1' ? $first : '0';
        })->sortKeysUsing(fn($a, $b) => ((int)$a ?: 99) <=> ((int)$b ?: 99));

        foreach ($byGroup as $key => $items) {
            $ids = $items->pluck('id')->all();
            $children = $items->filter(fn($a) => $a->parent_id && in_array($a->parent_id, $ids))
                ->groupBy('parent_id');
            $roots = $items->filter(fn($a) => !$a->parent_id || !in_array($a->parent_id, $ids));
            $names = $accounts->pluck('name', 'id');

            $rows = [];
            $walk = function ($account, $depth) use (&$walk, &$rows, $children, $counts, $names) {
                $rows[] = [
                    'account' => $account,
                    'depth' => $depth,
                    'entries' => (int)($counts[$account->id] ?? 0),
                    'has_children' => $children->has($account->id),
                    'parent_name' => $account->parent_id ? ($names[$account->parent_id] ?? null) : null,
                ];
                foreach ($children->get($account->id, []) as $child) {
                    $walk($child, $depth + 1);
                }
            };

            foreach ($roots as $root) {
                $walk($root, 0);
            }

            $groups[] = [
                'key' => (string)$key,
                'accounts' => $items->count(),
                'with_entries' => $items->filter(fn($a) => ($counts[$a->id] ?? 0) > 0)->count(),
                'entries' => (int)$items->sum(fn($a) => $counts[$a->id] ?? 0),
                'rows' => $rows,
            ];
        }

        return $groups;
    }

}
