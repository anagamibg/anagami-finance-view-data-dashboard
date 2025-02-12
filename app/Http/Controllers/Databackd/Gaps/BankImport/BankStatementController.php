<?php

namespace App\Http\Controllers\Databackd\Gaps\BankImport;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinBankStatement;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinImportBatch;
use App\Services\Databackd\Data\FinEntryImporter;
use App\Services\Databackd\Gaps\BankImport\BankStatementImporter;
use App\Services\Databackd\Gaps\BankImport\BankStatementParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * List of the imported bank statements and the undo of an import.
 *
 * The undo of this screen also deletes the statement headers (fin_bank_statements); the import history page
 * (databackd.data.batches.*) deletes only the entries of a batch.
 */
class BankStatementController extends AccountBaseController
{

    /** Statements shown in the list (newest first). */
    const LIMIT = 300;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-bank-import.statements.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        // AFV (S12): filters by legal entity and bank account, kept in the URL
        $entityId = (int)$request->query('entity_id');
        $iban = trim((string)$request->query('account'));
        $accounts = FinBankStatement::query()->distinct()->orderBy('account_iban')->pluck('account_iban')->filter()->map(fn($v) => (string)$v)->values()->all();
        $iban = in_array($iban, $accounts, true) ? $iban : '';
        $entities = FinEntity::orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $entityId = $entities->contains('id', $entityId) ? $entityId : 0;

        $query = FinBankStatement::query()
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->when($iban !== '', fn($q) => $q->where('account_iban', $iban));

        $total = (clone $query)->count();

        $this->statements = $query->with(['entity:id,name', 'importBatch:id,status,source,created_at,rows_failed'])
            ->orderByDesc('date_to')->orderByDesc('id')->limit(self::LIMIT)->get();
        $this->entities = $entities;
        $this->accounts = $accounts;
        $this->filterEntity = $entityId;
        $this->filterAccount = $iban;
        $this->filtered = $entityId > 0 || $iban !== '';
        $this->allCount = $this->filtered ? FinBankStatement::count() : $total;
        $this->total = $total;
        $this->limit = self::LIMIT;
        $this->active = $this->statements->filter(fn($s) => ($s->importBatch->status ?? null) !== FinEntryImporter::STATUS_UNDONE)->count();
        $this->entriesRoute = Route::has('databackd.data.entries.index');

        return view('databackd.gaps.bank-import.statements', $this->data);
    }

    public function undo($batch)
    {
        $batch = FinImportBatch::whereIn('source', BankStatementParser::FORMATS)->findOrFail($batch);

        if (in_array($batch->status, [FinEntryImporter::STATUS_PROCESSING, FinEntryImporter::STATUS_PENDING], true)) {
            return Reply::error(__('databackd-gaps-bank-import.statements.undo_processing'));
        }

        $deleted = (new BankStatementImporter(company()->id))->undo($batch);

        return Reply::success(__('databackd-gaps-bank-import.statements.undo_done', ['count' => $deleted]));
    }

}
