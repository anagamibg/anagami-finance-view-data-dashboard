<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinEntity;
use App\Services\Databackd\Data\InvoiceExpenseSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Transfers paid invoices and approved expenses into fin_entries (see InvoiceExpenseSync).
 */
class FinSyncController extends AccountBaseController
{

    public const SESSION_KEY = 'databackd_sync_last_result';

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-sync.title');
            // audit 2026-10-30 (G11): + at least one of the source modules (invoices, expenses)
            abort_403(in_array('client', user_roles()) || (!in_array('invoices', user_modules()) && !in_array('expenses', user_modules())));

            return $next($request);
        });
    }

    /**
     * Explanation page, dry-run preview and the result of the last run.
     */
    public function index()
    {
        $this->entities = FinEntity::where('is_active', 1)->orderBy('sort')->orderBy('id')->get();
        $sync = new InvoiceExpenseSync(company()->id);
        $this->preview = $sync->preview();
        $this->defaultEntityId = $this->preview['entity']['id'] ?? null;
        $this->lastResult = session(self::SESSION_KEY);

        return view('databackd.data.sync.index', $this->data);
    }

    /**
     * Runs the transfer (AJAX, JSON). Optional `entity_id` replaces the default legal entity.
     */
    public function run(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'entity_id' => ['nullable', 'integer', Rule::exists('fin_entities', 'id')->where('company_id', company()->id)],
        ], [], ['entity_id' => __('databackd-data-sync.entity')]);

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        try {
            $summary = (new InvoiceExpenseSync(company()->id))->run(
                $request->filled('entity_id') ? (int)$request->entity_id : null,
                user()->id
            );
        } catch (RuntimeException $e) {
            return Reply::error(e(Str::limit($e->getMessage(), 200))); // AFV (S10): escaped - the toastr renders HTML
        }

        $summary['run_at'] = now()->format('d.m.Y H:i');
        session([self::SESSION_KEY => $summary]);

        $message = __('databackd-data-sync.messages.done', [
            'invoices' => $summary['invoices_created'],
            'expenses' => $summary['expenses_created'],
            'updated' => $summary['invoices_updated'] + $summary['expenses_updated'],
            'removed' => $summary['invoices_removed'] + $summary['expenses_removed'],
            'skipped' =>$summary['invoices_skipped'] + $summary['expenses_skipped'],
        ]);

        return Reply::successWithData($message, ['summary' => $summary, 'data' => $summary]);
    }

}
