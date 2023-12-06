<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Jobs\Databackd\ImportFinEntriesJob;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinImportBatch;
use App\Services\Databackd\Data\FinEntryImporter;
use App\Services\Databackd\Data\ImportTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Import wizard for fin_entries from Excel / CSV files:
 * 1. index/upload  2. mapping/preview  3. run -> result (+ progress polling).
 *
 * The uploaded file is kept in storage/app/databackd-imports/<company_id>/<token>.<ext> (token = uuid), the token travels in the URL / hidden field
 * and the file name + default entity are remembered in the session. The import itself runs as a chain of ImportFinEntriesJob chunks.
 */
class FinImportController extends AccountBaseController
{

    const QUEUE = 'databackd-import';
    const CHUNK_SIZE = 500;
    const PREVIEW_ROWS = 10;
    const SAMPLE_ROWS = 50;
    const MAX_ERRORS_SHOWN = 100;
    const EXTENSIONS = ['xlsx', 'xls', 'csv', 'txt'];

    /** Upload limit of the wizard in KB; the PHP limits (upload_max_filesize / post_max_size) can lower it, see maxUploadKb(). */
    const MAX_UPLOAD_KB = 20480;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-import.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    // ------------------------------------------------------------------ step 1

    public function index()
    {
        $importer = new FinEntryImporter(company()->id);

        $this->entities = $this->entities();
        $this->fields = $importer->fields();

        // AFV (S8): platform templates (Microinvest, Ajur, Plus-Minus, Business Navigator); ?template=<id> preselects one
        $templates = new ImportTemplates(company()->id);
        $this->platforms = $templates->platforms();
        $this->selectedTemplate = $this->requestedTemplate(request());
        $this->defaultPeriodDate = now()->subMonth()->endOfMonth()->format(company()->date_format);
        // AFV (S12): the real limit (PHP may allow less than 20 MB) - shown in the help and checked in the browser before the upload
        $this->maxUploadKb = $this->maxUploadKb();
        $this->maxUploadMb = $this->formatMb($this->maxUploadKb);

        return view('databackd.data.import.index', $this->data);
    }

    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // S6/P4: "mimes" checks the real, detected content type (fileinfo), not just the client-reported
            // extension - on top of the extension allow-list already enforced below and the magic-byte sniffing
            // in FinEntryImporter::readFile() (only the Xlsx/Xls reader for the actually-detected type is ever
            // used, never PhpSpreadsheet's auto-detection / its Gnumeric/ODS/Xml/Html readers).
            'import_file' => 'required|file|max:' . $this->maxUploadKb() . '|mimes:xlsx,xls,csv,txt',
            'entity_id' => ['nullable', Rule::exists('fin_entities', 'id')->where('company_id', company()->id)],
        ], [], $this->attributeNames());

        $validator->after(function ($validator) use ($request) {
            $file = $request->file('import_file');

            if ($file && $file->isValid() && (int)$file->getSize() === 0) {
                // AFV (S12): an empty file is not "of a wrong type" (its detected type is application/x-empty)
                $validator->errors()->forget('import_file');
                $validator->errors()->add('import_file', __('databackd-data-import.messages.empty_file'));
            }
            elseif ($file && $file->isValid() && !$validator->errors()->has('import_file')
                && !in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true)) {
                $validator->errors()->add('import_file', __('databackd-data-import.messages.file_type'));
            }

            // AFV (S12): PHP refused a too big file - say so instead of the generic "failed to upload"
            if ($file && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $validator->errors()->forget('import_file');
                $validator->errors()->add('import_file', __('databackd-data-import.messages.file_too_big', ['size' => $this->formatMb($this->maxUploadKb())]));
            }
        });

        // AFV (S8): platform template and the date of a trial balance
        $validator->after(function ($validator) use ($request) {
            if ($request->filled('template') && !$this->requestedTemplate($request)) {
                $validator->errors()->add('template', __('databackd-gaps-import-templates.messages.unknown_template'));
            }

            if ($request->filled('period_date') && !$this->requestedPeriodDate($request)) {
                $validator->errors()->add('period_date', __('databackd-gaps-import-templates.messages.invalid_date'));
            }
        });

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        $file = $request->file('import_file');
        $extension = strtolower($file->getClientOriginalExtension());
        $originalName = $file->getClientOriginalName();
        $token = (string)\Illuminate\Support\Str::uuid();
        $directory = $this->directory();

        File::ensureDirectoryExists($directory);
        $this->pruneOldFiles($directory);

        $file->move($directory, $token . '.' . $extension);
        $path = $directory . '/' . $token . '.' . $extension;
        $sha256 = (string)hash_file('sha256', $path); // AFV (S12): the same file imported again is reported in the mapping step

        try {
            $table = (new FinEntryImporter(company()->id))->readFile($path);
        } catch (Throwable $e) {
            @unlink($path);
            report($e);

            return Reply::error(__('databackd-data-import.messages.unreadable'));
        }

        if (!$table['headers'] || !$table['rows']) {
            @unlink($path);

            return Reply::error(__('databackd-data-import.messages.empty_file'));
        }

        // AFV (S8): a platform template adds computed columns and pre-fills the mapping (see ImportTemplates)
        $templateId = $this->requestedTemplate($request);
        $periodDate = $this->requestedPeriodDate($request);
        $afterClosing = $request->boolean('after_closing');

        if ($templateId) {
            $table = $this->withTemplate($table, ['template' => $templateId, 'period_date' => $periodDate, 'after_closing' => $afterClosing]);

            if (!empty($table['template']['error']) || !$table['rows']) {
                @unlink($path);

                return Reply::error(__('databackd-gaps-import-templates.messages.' . ($table['template']['error'] ?? 'no_rows')));
            }
        }

        Cache::put($this->sampleKey($token), $this->makeSample($table), now()->addHours(2));

        session()->put('databackd_import.' . $token, [
            'name' => $originalName,
            'ext' => $extension,
            'entity_id' => $request->input('entity_id') ?: null,
            'template' => $templateId, // AFV (S8)
            'period_date' => $periodDate, // AFV (S8)
            'after_closing' => $afterClosing, // AFV (S8): trial balance taken after the year-end closing
            'sha256' => $sha256, // AFV (S12)
        ]);

        return Reply::successWithData(__('databackd-data-import.messages.uploaded'), [
            'redirect_url' => route('databackd.data.import.mapping', ['token' => $token]),
        ]);
    }

    // ------------------------------------------------------------------ step 2

    public function mapping(Request $request)
    {
        $importer = new FinEntryImporter(company()->id);
        $meta = $this->meta((string)$request->query('token'));

        $this->meta = $meta;
        $this->token = $meta['token'] ?? null;
        $this->fields = $importer->fields();
        $this->entities = $this->entities();

        if ($meta) {
            $sample = $this->sample($meta, $importer);

            if (!$sample) {
                $this->meta = null;
            }
            else {
                $this->headers = $sample['headers'];
                $this->sampleRows = array_slice($sample['rows'], 0, 5);
                $this->totalRows = $sample['total'];
                $this->mapping = $importer->guessMapping($sample['headers']);

                if (!empty($sample['template'])) {
                    // AFV (S8): the template pre-fills the mapping; columns it did not find stay unmapped (manual)
                    $this->mapping = $sample['template']['mapping'] ?? [];
                    $this->templateInfo = (new ImportTemplates(company()->id))->describe($sample['template']);
                    $this->virtualColumns = $sample['template']['virtual_columns'] ?? [];
                }

                // AFV (S12): a file with the same columns as an earlier import gets the mapping chosen then
                $this->rememberedBatch = null;

                if (empty($sample['template'])) {
                    $remembered = $this->rememberedMapping($sample['headers']);

                    if ($remembered) {
                        [$this->rememberedBatch, $this->mapping] = $remembered;
                    }
                }

                // AFV (S12): the very same file was imported before (and not undone) - a second import doubles the figures
                $this->sameFileBatch = !empty($meta['sha256'])
                    ? FinImportBatch::where('mapping->_file_sha256', $meta['sha256'])
                        ->whereNotIn('status', [FinEntryImporter::STATUS_UNDONE, FinEntryImporter::STATUS_FAILED])
                        ->orderByDesc('id')->first()
                    : null;

                $this->entityId = $meta['entity_id'] ?: ($this->entities->first()->id ?? null);
                $this->missing = $importer->missingRequired($this->mapping);
            }
        }

        return view('databackd.data.import.mapping', $this->data);
    }

    /**
     * AJAX: validate the first rows with the posted mapping. Returns {html, valid, invalid, missing}.
     */
    public function preview(Request $request)
    {
        $importer = new FinEntryImporter(company()->id);
        $meta = $this->meta((string)$request->input('token'));
        $sample = $meta ? $this->sample($meta, $importer) : null;

        if (!$sample) {
            return Reply::error(__('databackd-data-import.messages.file_expired'));
        }

        $mapping = $importer->mappingFromColumns((array)$request->input('columns', []));
        $missing = $importer->missingRequired($mapping);
        $fields = $importer->fields();
        $preview = [];
        $duplicates = null;

        if (!$missing) {
            // the separators of the whole file (not only of the sample rows), the same ones run() uses
            $options = $this->importOptions($request) + array_filter(['decimals' => $sample['decimals'] ?? null, 'lines' => $sample['lines'] ?? null], fn($v) => $v !== null);
            $preview = $importer->preview($sample['rows'], $mapping, $options, self::PREVIEW_ROWS);
            // AFV (S12): rows of the sample that are already entries (the file / its data was imported before)
            $duplicates = $importer->existingMatches($sample['rows'], $mapping, $options, self::SAMPLE_ROWS);
        }

        $valid = collect($preview)->filter(fn($row) => empty($row['errors']))->count();

        $html = view('databackd.data.import._preview', [
            'preview' => $preview,
            'mapping' => $mapping,
            'missing' => $missing,
            'fields' => $fields,
            'headers' => $sample['headers'],
            'count' => count(array_slice($sample['rows'], 0, self::PREVIEW_ROWS)),
            'duplicates' => $duplicates,
        ])->render();

        return Reply::dataOnly([
            'status' => 'success',
            'html' => $html,
            'valid' => $valid,
            'invalid' => count($preview) - $valid,
            'missing' => $missing,
        ]);
    }

    // ------------------------------------------------------------------ step 3

    /**
     * Create the batch and dispatch the chunk jobs. With the sync queue everything runs inside this request.
     */
    public function run(Request $request)
    {
        $companyId = company()->id;
        $importer = new FinEntryImporter($companyId);

        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'columns' => 'required|array',
            'entity_id' => ['nullable', Rule::exists('fin_entities', 'id')->where('company_id', $companyId)],
        ], [], $this->attributeNames());

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        $meta = $this->meta((string)$request->input('token'));

        // the session entry is removed once the import is started: a second "Import" (double click, browser back) on the
        // same upload must not import it again while the queued chunks still need the file (see also runKey() below)
        if (!$meta || !session()->has('databackd_import.' . $meta['token'])) {
            return Reply::error(__('databackd-data-import.messages.file_expired'));
        }

        $mapping = $importer->mappingFromColumns((array)$request->input('columns'));
        $missing = $importer->missingRequired($mapping);

        if ($missing) {
            $names = collect($importer->fields())->whereIn('id', $missing)->pluck('name')->implode(', ');

            return Reply::error(__('databackd-data-import.messages.missing_required', ['fields' => $names]));
        }

        $options = $this->importOptions($request);

        if (empty($options['entity_id']) && !isset($mapping['entity'])) {
            return Reply::error(__('databackd-data-import.messages.entity_required'));
        }

        try {
            $table = $importer->readFile($meta['path']);
            $table = $this->withTemplate($table, $meta); // AFV (S8): same computed columns as in the mapping step
        } catch (Throwable $e) {
            report($e);

            return Reply::error(__('databackd-data-import.messages.unreadable'));
        }

        if (!empty($table['template']['error'])) {
            return Reply::error(__('databackd-gaps-import-templates.messages.' . $table['template']['error'])); // AFV (S8)
        }

        $rows = $table['rows'];
        $lines = count($table['lines'] ?? []) === count($rows) ? array_values($table['lines']) : []; // AFV (S12)

        if (!$rows) {
            return Reply::error(__('databackd-data-import.messages.empty_file'));
        }

        $options['decimals'] = $importer->detectDecimalSeparators($rows); // one decision for all chunks
        $source = in_array($meta['ext'], ['csv', 'txt'], true) ? 'csv' : 'excel';

        // atomic: of two simultaneous runs of the same upload only the first one creates a batch
        if (!Cache::add($this->runKey($meta['token']), true, now()->addHours(2))) {
            return Reply::error(__('databackd-data-import.messages.file_expired'));
        }

        // The batch is created up front (no rows yet) so the progress bar has something to read from the very beginning.
        $options['keep_values'] = true; // AFV (S12): the failed rows can be downloaded, fixed and imported again

        $batch = $importer->import([], $mapping, $options + [
            'source' => $source,
            'file_name' => $meta['name'],
            'user_id' => user()->id,
            'total_rows' => count($rows),
            'finalize' => false,
            // AFV (S12): the columns of the file (remembered mapping, header of the errors download) and its hash (same file again)
            'batch_meta' => array_filter([
                '_headers' => array_map(fn($h) => mb_substr((string)$h, 0, 100), array_slice($table['headers'], 0, FinEntryImporter::MAX_ERROR_VALUE_COLUMNS)),
                '_headers_sig' => empty($meta['template']) ? $importer->headersSignature($table['headers']) : null,
                '_file_sha256' => $meta['sha256'] ?? null,
            ]),
        ]);

        $chunks = array_chunk($rows, self::CHUNK_SIZE);
        $jobs = [];

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ImportFinEntriesJob(
                $companyId,
                $batch->id,
                $chunk,
                $mapping,
                $options + ($lines ? ['lines' => array_slice($lines, $index * self::CHUNK_SIZE, self::CHUNK_SIZE)] : []),
                $index * self::CHUNK_SIZE,
                $index === count($chunks) - 1,
                $meta['path'],
                app()->getLocale()
            );
        }

        try {
            Bus::chain($jobs)->onQueue(self::QUEUE)->dispatch();
        } catch (Throwable $e) {
            report($e);

            // the run key stays: with the sync queue earlier chunks may already be imported, a retry would duplicate them.
            // A batch that nothing was written to must not stay "processing" (it could never be undone or finished).
            $batch->refresh();

            if ($this->isRunning($batch) && $batch->rows_imported + $batch->rows_failed === 0) {
                $batch->status = FinEntryImporter::STATUS_FAILED;
                $batch->save();
            }

            // the message is shown as HTML by toastr, and an exception text may hold internals (already in the log through report())
            return Reply::error(__('databackd-data-import.messages.run_failed', ['message' => e(\Illuminate\Support\Str::limit($e->getMessage(), 200))]));
        }

        session()->forget('databackd_import.' . $meta['token']);
        Cache::forget($this->sampleKey($meta['token']));

        return Reply::successWithData(__('databackd-data-import.messages.started'), [
            'batch_id' => $batch->id,
            'redirect_url' => route('databackd.data.import.result', ['batch' => $batch->id]),
        ]);
    }

    /**
     * JSON progress of a batch. With a real queue (not sync) every poll also lets a short-lived worker process some chunks
     * (same approach as ImportController::getImportProgress), so the import advances even without a permanent worker.
     */
    public function progress($batch)
    {
        $batch = FinImportBatch::findOrFail($batch);

        if ($this->isRunning($batch) && config('queue.default') !== 'sync') {
            try {
                Artisan::call('queue:work', [
                    '--queue' => self::QUEUE,
                    '--max-jobs' => 4,
                    '--max-time' => 20,
                    '--stop-when-empty' => true,
                ]);
            } catch (Throwable $e) {
                report($e);
            }

            $batch->refresh();
        }

        $processed = $batch->rows_imported + $batch->rows_failed;
        $finished = !$this->isRunning($batch);
        $percent = $batch->rows_total > 0 ? min(100, (int)floor($processed / $batch->rows_total * 100)) : 0;

        if ($finished) {
            $percent = 100;
        }
        elseif ($percent >= 100) {
            $percent = 99;
        }

        return Reply::dataOnly([
            'status' => $batch->status,
            'status_label' => __('databackd-data-common.statuses.' . $batch->status),
            'rows_total' => (int)$batch->rows_total,
            'rows_imported' => (int)$batch->rows_imported,
            'rows_failed' => (int)$batch->rows_failed,
            'processed' => $processed,
            'percent' => $percent,
            'finished' => $finished,
        ]);
    }

    public function result($batch)
    {
        $batch = FinImportBatch::findOrFail($batch);

        $errors = collect($batch->errors ?: []);

        $this->batch = $batch;
        $this->running = $this->isRunning($batch);
        $this->errorRows = $errors->take(self::MAX_ERRORS_SHOWN)->values();
        $this->errorCount = max($errors->count(), (int)$batch->rows_failed);
        $this->errorsShown = $this->errorRows->count();
        $this->createdBy = $batch->created_by ? \App\Models\User::withoutGlobalScopes()->find($batch->created_by) : null;
        $this->canUndo = FinImportBatchController::canUndo($batch); // AFV (S12): undo right from the result

        return view('databackd.data.import.result', $this->data);
    }

    // ------------------------------------------------------------------ templates

    public function template(Request $request): BinaryFileResponse
    {
        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';
        $path = public_path('databackd/templates/fin-entries-template.' . $format);

        abort_if(!is_file($path), 404);

        return response()->download($path, 'databackd-import-template.' . $format);
    }

    // ------------------------------------------------------------------ helpers

    protected function entities()
    {
        return FinEntity::where('is_active', true)->orderBy('sort')->orderBy('name')->get();
    }

    protected function attributeNames(): array
    {
        return trans('databackd-data-import.attributes');
    }

    protected function directory(): string
    {
        return storage_path('app/databackd-imports/' . company()->id);
    }

    /**
     * Delete uploads that were never imported (older than two days).
     */
    protected function pruneOldFiles(string $directory): void
    {
        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < now()->subDays(2)->getTimestamp()) {
                @unlink($file->getPathname());
            }
        }
    }

    protected function sampleKey(string $token): string
    {
        return 'databackd-import-sample-' . company()->id . '-' . $token;
    }

    protected function runKey(string $token): string
    {
        return 'databackd-import-run-' . company()->id . '-' . $token;
    }

    /**
     * @return array{headers: array, rows: array, total: int, decimals: array}
     */
    protected function makeSample(array $table): array
    {
        return [
            'headers' => $table['headers'],
            'rows' => array_slice($table['rows'], 0, self::SAMPLE_ROWS),
            'total' => count($table['rows']),
            'decimals' => (new FinEntryImporter(company()->id))->detectDecimalSeparators($table['rows']), // decimal separator per column
            'template' => $table['template'] ?? null, // AFV (S8): result of ImportTemplates::apply() without the rows
            'lines' => array_slice($table['lines'] ?? [], 0, self::SAMPLE_ROWS), // AFV (S12): physical row numbers
        ];
    }

    /**
     * Headers + first rows of the uploaded file (cached, so the live preview does not re-read the file).
     */
    protected function sample(array $meta, FinEntryImporter $importer): ?array
    {
        $sample = Cache::get($this->sampleKey($meta['token']));

        if ($sample) {
            return $sample;
        }

        try {
            $sample = $this->makeSample($this->withTemplate($importer->readFile($meta['path']), $meta));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        Cache::put($this->sampleKey($meta['token']), $sample, now()->addHours(2));

        return $sample;
    }

    /**
     * Uploaded file of the current company by token: ['token', 'path', 'ext', 'name', 'entity_id'] or null.
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

        $stored = (array)session('databackd_import.' . $token, []);
        $extension = strtolower(pathinfo($files[0], PATHINFO_EXTENSION));

        return [
            'token' => $token,
            'path' => $files[0],
            'ext' => $extension,
            'name' => $stored['name'] ?? basename($files[0]),
            'entity_id' => $stored['entity_id'] ?? null,
            'template' => $stored['template'] ?? null, // AFV (S8)
            'period_date' => $stored['period_date'] ?? null, // AFV (S8)
            'after_closing' => (bool)($stored['after_closing'] ?? false), // AFV (S8)
            // AFV (S12): computed again when the session is gone (it only exists to warn about the same file)
            'sha256' => $stored['sha256'] ?? (string)hash_file('sha256', $files[0]),
        ];
    }

    protected function importOptions(Request $request): array
    {
        $entityId = (int)$request->input('entity_id');

        return [
            'entity_id' => $entityId && FinEntity::where('id', $entityId)->exists() ? $entityId : null,
            'create_missing_dimensions' => $request->boolean('create_missing_dimensions'),
        ];
    }

    // ------------------------------------------------------------------ AFV (S8): platform templates

    /**
     * Template id of the request (?template= / template=) when it exists in the catalog, otherwise null.
     */
    protected function requestedTemplate(Request $request): ?string
    {
        $id = $request->input('template', $request->query('template'));

        return is_string($id) && (new ImportTemplates(company()->id))->exists($id) ? $id : null;
    }

    /**
     * "Date of the trial balance" of the form (company date format) as Y-m-d, or null when empty / invalid.
     */
    protected function requestedPeriodDate(Request $request): ?string
    {
        $value = $request->input('period_date');

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return companyToYmd(trim($value));
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Apply the template of $meta (['template' => id, 'period_date' => Y-m-d]) to a table: headers and rows get the computed columns and
     * `template` holds the rest of ImportTemplates::apply() (mapping, found/missing columns, skipped rows, error). Without a template the table is unchanged.
     *
     * @return array{headers: array, rows: array, template?: array}
     */
    protected function withTemplate(array $table, array $meta): array
    {
        if (empty($meta['template'])) {
            return $table;
        }

        $result = (new ImportTemplates(company()->id))->apply($meta['template'], $table,
            ['date' => $meta['period_date'] ?? null, 'after_closing' => !empty($meta['after_closing'])]);
        $table = ['headers' => $result['headers'], 'rows' => $result['rows'], 'lines' => $result['lines'] ?? []];
        unset($result['headers'], $result['rows'], $result['lines']);
        $table['template'] = $result;

        return $table;
    }

    /**
     * AFV (S12): mapping of the latest import of a file with exactly the same columns: [batch, mapping] or null. Only the
     * fields that still point to an existing column are used, and only when the required fields are among them.
     */
    protected function rememberedMapping(array $headers): ?array
    {
        $importer = new FinEntryImporter(company()->id);

        // a "rows with errors" download (FinImportBatchController::errorsExport) has two extra columns at the end: the same file otherwise
        $extra = [];

        foreach (['bg', 'eng'] as $locale) {
            $extra[] = mb_strtolower(__('databackd-data-batches.csv.file_row', [], $locale));
            $extra[] = mb_strtolower(__('databackd-data-batches.csv.reasons', [], $locale));
        }

        while ($headers && in_array(mb_strtolower(trim((string)end($headers))), $extra, true)) {
            array_pop($headers);
        }

        $batch = FinImportBatch::where('mapping->_headers_sig', $importer->headersSignature($headers))
            ->whereIn('source', ['excel', 'csv'])
            ->whereIn('status', [FinEntryImporter::STATUS_COMPLETED, FinEntryImporter::STATUS_PARTIAL, FinEntryImporter::STATUS_UNDONE])
            ->orderByDesc('id')
            ->first();

        if (!$batch) {
            return null;
        }

        $known = array_column($importer->fields(), 'id');
        $mapping = [];

        foreach ((array)$batch->mapping as $field => $index) {
            if (in_array($field, $known, true) && is_numeric($index) && (int)$index >= 0 && (int)$index < count($headers)) {
                $mapping[$field] = (int)$index;
            }
        }

        return $mapping && !$importer->missingRequired($mapping) ? [$batch, $mapping] : null;
    }

    /** AFV (S12): effective upload limit in KB = min(MAX_UPLOAD_KB, upload_max_filesize, post_max_size). */
    protected function maxUploadKb(): int
    {
        $limit = self::MAX_UPLOAD_KB;

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = $this->iniBytes((string)ini_get($key));

            if ($bytes > 0) {
                $limit = min($limit, (int)floor($bytes / 1024));
            }
        }

        return max(1, $limit);
    }

    protected function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)/i', $value, $m)) {
            return 0;
        }

        return (int)((float)$m[1] * ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2])]);
    }

    /** "20", "1,5" (MB, Bulgarian decimal comma). */
    protected function formatMb(int $kb): string
    {
        $mb = round($kb / 1024, 1);

        return $mb == (int)$mb ? (string)(int)$mb : number_format($mb, 1, ',', '');
    }

    protected function isRunning(FinImportBatch $batch): bool
    {
        return in_array($batch->status, [FinEntryImporter::STATUS_PENDING, FinEntryImporter::STATUS_PROCESSING], true);
    }

}
