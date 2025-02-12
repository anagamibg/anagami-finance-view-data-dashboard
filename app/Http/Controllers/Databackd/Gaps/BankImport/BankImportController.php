<?php

namespace App\Http\Controllers\Databackd\Gaps\BankImport;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinBankStatement;
use App\Models\Databackd\FinCounterpartyMapping;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinImportBatch;
use App\Services\Databackd\Gaps\BankImport\BankStatementException;
use App\Services\Databackd\Gaps\BankImport\BankStatementImporter;
use App\Services\Databackd\Gaps\BankImport\BankStatementParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Import wizard for bank statements (MT940 / CAMT.053):
 * 1. index/upload (file + legal entity)  2. review (accounts of the transactions, balances, duplicates)  3. run -> result.
 *
 * The uploaded file is kept in storage/app/databackd-bank-imports/<company_id>/<token>.<ext> (token = uuid) until it is imported
 * (or two days); the file name and the chosen legal entity are remembered in the session. The review and the import parse the
 * file again, so both always see the same transactions - the rows of the review are identified by their position in the file.
 */
class BankImportController extends AccountBaseController
{

    const EXTENSIONS = ['sta', 'mt940', '940', 'swi', 'txt', 'xml'];

    /** Rows rendered in the review; the rest of a bigger statement is imported with the suggested accounts. */
    const MAX_REVIEW_ROWS = 1000;

    const SAMPLES = ['mt940' => 'mt940-sample.sta', 'camt053' => 'camt053-sample.xml'];

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-bank-import.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    // ------------------------------------------------------------------ step 1

    public function index()
    {
        $this->entities = $this->entities();
        $this->recent = FinBankStatement::with(['entity:id,name', 'importBatch:id,status,created_at'])->orderByDesc('id')->limit(5)->get();
        $this->statementsCount = FinBankStatement::count();
        $this->rulesCount = FinCounterpartyMapping::count();
        $this->maxMb = BankStatementParser::MAX_BYTES / 1024 / 1024;

        return view('databackd.gaps.bank-import.index', $this->data);
    }

    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'statement_file' => 'required|file|max:' . (BankStatementParser::MAX_BYTES / 1024),
            'entity_id' => ['required', Rule::exists('fin_entities', 'id')->where('company_id', company()->id)],
        ], [], __('databackd-gaps-bank-import.attributes'));

        $validator->after(function ($validator) use ($request) {
            $file = $request->file('statement_file');

            if ($file && $file->isValid() && !in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true)) {
                $validator->errors()->add('statement_file', __('databackd-gaps-bank-import.errors.file_type', ['types' => '.' . implode(', .', self::EXTENSIONS)]));
            }
        });

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        $file = $request->file('statement_file');
        $extension = strtolower($file->getClientOriginalExtension());
        $originalName = $file->getClientOriginalName();
        $token = (string)\Illuminate\Support\Str::uuid();
        $directory = $this->directory();

        File::ensureDirectoryExists($directory);
        $this->pruneOldFiles($directory);

        $file->move($directory, $token . '.' . $extension);
        $path = $directory . '/' . $token . '.' . $extension;

        // read it once now, so a wrong file is refused at the upload and not in the next step
        try {
            (new BankStatementParser())->parseFile($path, $originalName);
        } catch (BankStatementException $e) {
            @unlink($path);

            // AFV (S10): toastr shows the message as HTML and parser messages can quote the file
            return Reply::error(e(Str::limit($e->getMessage(), 200)));
        } catch (Throwable $e) {
            @unlink($path);
            report($e);

            return Reply::error(__('databackd-gaps-bank-import.errors.unreadable'));
        }

        session()->put('databackd_bank_import.' . $token, [
            'name' => $originalName,
            'entity_id' => (int)$request->input('entity_id'),
        ]);

        return Reply::successWithData(__('databackd-gaps-bank-import.messages.uploaded'), [
            'redirect_url' => route('databackd.gaps.bank-import.review', ['token' => $token]),
        ]);
    }

    // ------------------------------------------------------------------ step 2

    public function review(Request $request)
    {
        $meta = $this->meta((string)$request->query('token'));
        $parsed = null;

        $this->entities = $this->entities();
        $this->meta = $meta;
        $this->fileError = null;

        if ($meta) {
            try {
                $parsed = (new BankStatementParser())->parseFile($meta['path'], $meta['name']);
            } catch (BankStatementException $e) {
                $this->fileError = $e->getMessage();
                $this->meta = null;
            } catch (Throwable $e) {
                report($e);
                $this->fileError = __('databackd-gaps-bank-import.errors.unreadable');
                $this->meta = null;
            }
        }

        if ($parsed) {
            $entityId = (int)$request->query('entity_id') ?: ($meta['entity_id'] ?: ($this->entities->first()->id ?? 0));

            if (!$this->entities->contains('id', $entityId)) {
                $entityId = $this->entities->first()->id ?? 0;
            }

            $importer = new BankStatementImporter(company()->id);

            $this->token = $meta['token'];
            $this->parsed = $parsed;
            $this->entityId = $entityId;
            $this->review = $importer->review($parsed, $entityId ?: null);
            $this->creditAccounts = $importer->accountsFor('credit');
            $this->debitAccounts = $importer->accountsFor('debit');
            $this->cashAccounts = $importer->cashAccounts();
            $this->maxRows = self::MAX_REVIEW_ROWS;
        }

        return view('databackd.gaps.bank-import.review', $this->data);
    }

    // ------------------------------------------------------------------ step 3

    public function run(Request $request)
    {
        $companyId = company()->id;

        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'entity_id' => ['required', Rule::exists('fin_entities', 'id')->where('company_id', $companyId)],
            'decisions' => 'nullable|string|max:2000000',
            'cash_account_id' => ['nullable', Rule::exists('fin_accounts', 'id')->where('company_id', $companyId)],
        ], [], __('databackd-gaps-bank-import.attributes'));

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        $meta = $this->meta((string)$request->input('token'));

        if (!$meta) {
            return Reply::error(__('databackd-gaps-bank-import.messages.file_expired'));
        }

        try {
            $parsed = (new BankStatementParser())->parseFile($meta['path'], $meta['name']);
            $batch = (new BankStatementImporter($companyId))->import($parsed, (int)$request->input('entity_id'), [
                'file_name' => $meta['name'],
                'user_id' => user()->id,
                'cash_account_id' => $request->input('cash_account_id') ?: null,
                'record_balances' => $request->boolean('record_balances'),
                'allow_overlap' => $request->boolean('allow_overlap'),
                'decisions' => $this->decisions((string)$request->input('decisions')),
            ]);
        } catch (BankStatementException $e) {
            // AFV (S10): toastr shows the message as HTML
            return Reply::error(e(Str::limit($e->getMessage(), 200)));
        } catch (Throwable $e) {
            report($e);

            // AFV (S10): shown as HTML by toastr, and an exception text may hold internals (already in the log through report())
            return Reply::error(__('databackd-gaps-bank-import.messages.run_failed', ['message' => e(Str::limit($e->getMessage(), 200))]));
        }

        @unlink($meta['path']);
        session()->forget('databackd_bank_import.' . $meta['token']);

        return Reply::successWithData(__('databackd-gaps-bank-import.messages.imported'), [
            'batch_id' => $batch->id,
            'redirect_url' => route('databackd.gaps.bank-import.result', ['batch' => $batch->id]),
        ]);
    }

    public function result($batch)
    {
        $batch = FinImportBatch::whereIn('source', BankStatementParser::FORMATS)->findOrFail($batch);

        $statements = FinBankStatement::with('entity:id,name')->where('import_batch_id', $batch->id)->orderBy('date_from')->orderBy('id')->get();

        // totals of what was booked, by direction of the account
        $totals = DB::table('fin_entries')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.company_id', company()->id)
            ->where('fin_entries.import_batch_id', $batch->id)
            ->where('fin_entries.kind', 'flow')
            ->groupBy('fin_accounts.type')
            ->selectRaw('fin_accounts.type as type, COUNT(*) as entries, SUM(fin_entries.amount_base) as total')
            ->get()->keyBy('type');

        $this->batch = $batch;
        $this->statements = $statements;
        $this->receipts = (float)($totals['revenue']->total ?? 0);
        $this->payments = (float)($totals['expense']->total ?? 0);
        $this->receiptsCount = (int)($totals['revenue']->entries ?? 0);
        $this->paymentsCount = (int)($totals['expense']->entries ?? 0);
        $this->balanceEntries = DB::table('fin_entries')->where('company_id', company()->id)
            ->where('import_batch_id', $batch->id)->where('kind', 'balance')->count();
        $this->skipped = max(0, (int)$batch->rows_total - (int)$batch->rows_imported - (int)$batch->rows_failed);
        $this->warnings = collect($batch->errors ?: [])->filter(fn($e) => is_array($e))->take(50)->values();
        $this->entriesUrl = Route::has('databackd.data.entries.index') ? route('databackd.data.entries.index', ['batch_id' => $batch->id]) : null;

        return view('databackd.gaps.bank-import.result', $this->data);
    }

    // ------------------------------------------------------------------ samples

    public function sample(string $format): BinaryFileResponse
    {
        abort_unless(isset(self::SAMPLES[$format]), 404);

        $path = public_path('databackd/templates/bank-statements/' . self::SAMPLES[$format]);

        abort_if(!is_file($path), 404);

        return response()->download($path, self::SAMPLES[$format]);
    }

    // ------------------------------------------------------------------ helpers

    protected function entities()
    {
        return FinEntity::where('is_active', true)->orderBy('sort')->orderBy('name')->get();
    }

    protected function directory(): string
    {
        return storage_path('app/databackd-bank-imports/' . company()->id);
    }

    /** Delete uploads that were never imported (older than two days). */
    protected function pruneOldFiles(string $directory): void
    {
        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < now()->subDays(2)->getTimestamp()) {
                @unlink($file->getPathname());
            }
        }
    }

    /**
     * Uploaded file of the current company by token: ['token', 'path', 'name', 'entity_id'] or null.
     */
    protected function meta(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token)) {
            return null;
        }

        $files = glob($this->directory() . '/' . $token . '.*') ?: [];

        if (!$files) {
            return null;
        }

        $stored = (array)session('databackd_bank_import.' . $token, []);

        return [
            'token' => $token,
            'path' => $files[0],
            'name' => $stored['name'] ?? basename($files[0]),
            'entity_id' => (int)($stored['entity_id'] ?? 0),
        ];
    }

    /**
     * The choices made in the review, as posted by the page: JSON {default_credit, default_debit, accounts: {idx: id|0},
     * include: {idx: 0|1}, remember: {idx: 1}} - only values that differ from the suggestions are sent.
     */
    protected function decisions(string $json): array
    {
        $data = json_decode($json, true);
        $data = is_array($data) ? $data : [];

        $map = fn($values, callable $cast) => is_array($values)
            ? collect($values)->filter(fn($v, $k) => is_numeric($k))->mapWithKeys(fn($v, $k) => [(int)$k => $cast($v)])->all()
            : [];

        $decisions = [
            'accounts' => $map($data['accounts'] ?? [], fn($v) => (int)$v),
            'include' => $map($data['include'] ?? [], fn($v) => (bool)$v),
            'remember' => $map($data['remember'] ?? [], fn($v) => (bool)$v),
        ];

        // 0 / missing value = "do not import transactions without a rule"; a missing key = the suggested default account
        foreach (['default_credit', 'default_debit'] as $key) {
            if (array_key_exists($key, $data)) {
                $decisions[$key] = (int)$data[$key] ?: null;
            }
        }

        return $decisions;
    }

}
