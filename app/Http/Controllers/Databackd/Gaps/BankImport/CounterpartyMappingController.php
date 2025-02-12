<?php

namespace App\Http\Controllers\Databackd\Gaps\BankImport;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinCounterpartyMapping;
use App\Models\Databackd\FinEntity;
use App\Services\Databackd\Gaps\BankImport\CounterpartyMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Rules that book the transactions of a bank counterparty (name, IBAN or text) to an account: list, add, edit, delete.
 * The rules are applied by CounterpartyMatcher when a statement is reviewed.
 */
class CounterpartyMappingController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-bank-import.rules.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index()
    {
        $this->rules = FinCounterpartyMapping::with('account:id,code,name,type,is_active')->orderByDesc('id')->get();
        $this->entities = FinEntity::orderBy('sort')->orderBy('name')->get(['id', 'name'])->keyBy('id');

        return view('databackd.gaps.bank-import.rules.index', $this->data);
    }

    public function create()
    {
        return $this->form(new FinCounterpartyMapping(['match_type' => 'contains', 'direction' => 'any']));
    }

    public function store(Request $request)
    {
        $result = $this->validated($request);

        if (isset($result['reply'])) {
            return $result['reply'];
        }

        $rule = new FinCounterpartyMapping();
        $rule->company_id = company()->id;
        $rule->created_by = user()->id;
        $this->fill($rule, $result['data']);

        return Reply::success(__('databackd-gaps-bank-import.rules.messages.created'));
    }

    public function edit($id)
    {
        return $this->form(FinCounterpartyMapping::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $rule = FinCounterpartyMapping::findOrFail($id);
        $result = $this->validated($request, $rule->id);

        if (isset($result['reply'])) {
            return $result['reply'];
        }

        $this->fill($rule, $result['data']);

        return Reply::success(__('databackd-gaps-bank-import.rules.messages.updated'));
    }

    public function destroy($id)
    {
        FinCounterpartyMapping::findOrFail($id)->delete();

        return Reply::success(__('databackd-gaps-bank-import.rules.messages.deleted'));
    }

    // ------------------------------------------------------------------

    protected function form(FinCounterpartyMapping $rule)
    {
        $this->rule = $rule;
        // AFV (S12): the current account of the rule stays in the list even when it was deactivated (marked in the form)
        $this->accounts = FinAccount::whereIn('type', ['revenue', 'expense'])
            ->where(fn($q) => $q->where('is_active', true)->when($rule->account_id, fn($q) => $q->orWhere('id', $rule->account_id)))
            ->orderBy('code')->get(['id', 'code', 'name', 'type', 'is_active']);
        $this->entities = FinEntity::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name']);

        return view('databackd.gaps.bank-import.rules.form', $this->data);
    }

    protected function fill(FinCounterpartyMapping $rule, array $data): void
    {
        $rule->pattern = $data['pattern'];
        $rule->match_type = $data['match_type'];
        $rule->direction = $data['direction'];
        $rule->account_id = $data['account_id'];
        $rule->entity_id = $data['entity_id'];
        $rule->counterparty_name = $data['counterparty_name'];
        $rule->save();
    }

    /**
     * @return array{data: array}|array{reply: array}
     */
    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $companyId = company()->id;

        $validator = Validator::make($request->all(), [
            // AFV (S12): one character would match almost every transaction; 191 = the column (varchar(191))
            'pattern' => ['required', 'string', 'min:2', 'max:191'],
            'match_type' => ['required', Rule::in(FinCounterpartyMapping::MATCH_TYPES)],
            'direction' => ['required', Rule::in(FinCounterpartyMapping::DIRECTIONS)],
            'account_id' => ['required', Rule::exists('fin_accounts', 'id')->where('company_id', $companyId)],
            'entity_id' => ['nullable', Rule::exists('fin_entities', 'id')->where('company_id', $companyId)],
            'counterparty_name' => ['nullable', 'string', 'max:191'],
        ], [], __('databackd-gaps-bank-import.attributes'));

        if ($validator->fails()) {
            return ['reply' => Reply::formErrors($validator)];
        }

        $account = FinAccount::find($request->input('account_id'));
        $direction = $request->input('direction');

        // money in is booked to revenue, money out to expenses
        if (!$account || !in_array($account->type, ['revenue', 'expense'], true)) {
            return ['reply' => Reply::error(__('databackd-gaps-bank-import.rules.messages.account_type'))];
        }

        // AFV (S12): the matcher ignores rules of inactive accounts - only the rule's current account may stay inactive
        if (!$account->is_active && (!$ignoreId || (int)FinCounterpartyMapping::whereKey($ignoreId)->value('account_id') !== (int)$account->id)) {
            return ['reply' => Reply::error(__('databackd-gaps-bank-import.rules.messages.account_inactive'))];
        }

        if (isset(CounterpartyMatcher::ACCOUNT_TYPE_BY_DIRECTION[$direction]) && $account->type !== CounterpartyMatcher::ACCOUNT_TYPE_BY_DIRECTION[$direction]) {
            return ['reply' => Reply::error(__('databackd-gaps-bank-import.rules.messages.account_direction', [
                'direction' => __('databackd-gaps-bank-import.directions.' . $direction),
                'type' => __('databackd-gaps-bank-import.account_types.' . CounterpartyMatcher::ACCOUNT_TYPE_BY_DIRECTION[$direction]),
            ]))];
        }

        $pattern = trim(preg_replace('/\s+/u', ' ', (string)$request->input('pattern')));

        if (mb_strlen($pattern) < 2) {
            return ['reply' => Reply::error(__('databackd-gaps-bank-import.rules.messages.pattern_short'))];
        }
        $entityId = $request->input('entity_id') ?: null;

        $exists = FinCounterpartyMapping::whereRaw('LOWER(pattern) = ?', [mb_strtolower($pattern)])
            ->where('match_type', $request->input('match_type'))
            ->where('direction', $direction)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId), fn($q) => $q->whereNull('entity_id'))
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            return ['reply' => Reply::error(__('databackd-gaps-bank-import.rules.messages.duplicate'))];
        }

        $name = trim((string)$request->input('counterparty_name'));

        return ['data' => [
            'pattern' => $pattern,
            'match_type' => $request->input('match_type'),
            'direction' => $direction,
            'account_id' => (int)$account->id,
            'entity_id' => $entityId ? (int)$entityId : null,
            'counterparty_name' => $name !== '' ? $name : null,
        ]];
    }

}
