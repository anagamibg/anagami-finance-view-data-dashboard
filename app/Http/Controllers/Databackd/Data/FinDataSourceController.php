<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinDataSource;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinImportBatch;
use App\Services\Databackd\Data\FinEntryImporter;
use App\Services\Databackd\Data\GoogleSheetsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * P6: import from Google Sheets / CSV URL and saved data sources.
 *
 * Flow: index (form) -> fetch (download + stash CSV) -> mapping -> preview (AJAX) -> import -> result.
 * The downloaded CSV is kept in storage/app/databackd-imports/<company_id>/<uuid>.csv (+ <uuid>.json with the form data) between the steps.
 */
class FinDataSourceController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-sources.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });

        // Downloading from a URL the user typed (fetch + "Синхронизирай сега") is limited so the server cannot be used as a proxy/DoS
        // tool against third parties: per user and per company.
        $this->middleware(function ($request, $next) {
            foreach ([['u:' . user()->id, 12, 300], ['c:' . company()->id, 60, 3600]] as [$name, $max, $decay]) {
                $key = 'databackd-source-fetch:' . $name;

                if (RateLimiter::tooManyAttempts($key, $max)) {
                    $seconds = RateLimiter::availableIn($key);
                    $full = 'databackd-data-sources.errors.rate_limited';
                    $message = Lang::has($full)
                        ? __($full, ['seconds' => $seconds])
                        : __('databackd-misc.source_rate_limited', ['seconds' => $seconds]);

                    return redirect()->back(302, [], route('databackd.data.sources.index'))->withInput()->with('error', $message);
                }
            }

            RateLimiter::hit('databackd-source-fetch:u:' . user()->id, 300);
            RateLimiter::hit('databackd-source-fetch:c:' . company()->id, 3600);

            return $next($request);
        })->only(['fetch', 'sync']);
    }

    // ------------------------------------------------------------------ screens

    public function index()
    {
        $this->sources = FinDataSource::with(['defaultEntity', 'lastBatch'])->orderBy('name')->get();
        $this->entities = $this->entities();
        $this->isLocal = app(GoogleSheetsReader::class)->canReadLocally();
        $this->templateUrl = $this->isLocal ? 'public/databackd/templates/fin-entries-template.csv' : '';

        return view('databackd.data.sources.index', $this->data);
    }

    /** Step 2: download the sheet / CSV, stash it and go to the column mapping. */
    public function fetch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:google_sheets,csv_url'],
            'url' => ['required', 'string', 'max:2000'],
            'sheet' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:150'],
            'default_entity_id' => ['nullable', 'integer'],
        ], [], $this->attributes());

        $type = $validated['type'];
        $url = trim($validated['url']);
        $sheet = trim((string)($validated['sheet'] ?? '')) ?: null;
        $entityId = $this->validEntityId($validated['default_entity_id'] ?? null);

        try {
            $reader = app(GoogleSheetsReader::class, ['companyId' => company()->id]);
            // the URL is refused here (before any network access and before anything is stored) when it is not an allowed source
            $reader->assertSourceUrl($type, $url);
            $csv = $reader->fetchForType($type, $url, $sheet);
        } catch (\RuntimeException $e) {
            $this->logFailedFetch($type, $url, $e);

            return back()->withInput()->with('error', $e->getMessage());
        }

        $table = $this->importer()->readCsvString($csv);

        if (!$table['headers'] || !$table['rows']) {
            return back()->withInput()->with('error', __('databackd-data-sources.errors.empty_sheet'));
        }

        // the same sheet saved before (any form of its link) is updated instead of being saved a second time - only when the user
        // ticks "remember" on the mapping page; otherwise the rows are a plain additional import and the source is not touched
        $saved = $this->findSavedSource($type, $url, $sheet);

        $meta = [
            'type' => $type,
            'url' => $url,
            'sheet' => $sheet,
            'name' => trim((string)($validated['name'] ?? '')) ?: (string)$saved?->name,
            'remember' => $request->boolean('remember'),
            'entity_id' => $entityId ?? $saved?->default_entity_id,
            'source_id' => $saved?->id,
            'file_name' => $this->fileName($type, $url, $sheet),
        ];

        $token = $this->stash($csv, $meta);

        return redirect()->route('databackd.data.sources.mapping', $token);
    }

    /** Step 3: column mapping of the downloaded sheet. */
    public function mapping(?string $token = null)
    {
        $this->entities = $this->entities();
        $this->token = null;
        $stash = $token ? $this->loadStash($token) : null;

        if ($stash) {
            $importer = $this->importer();
            $table = $importer->readCsvString($stash['csv']);
            $this->token = $token;
            $this->meta = $stash['meta'];
            $this->headers = $table['headers'];
            $this->totalRows = count($table['rows']);
            $this->sampleRows = array_slice($table['rows'], 0, 8);
            $this->fields = $importer->fields();

            $old = old('columns');
            $saved = !empty($this->meta['source_id']) ? FinDataSource::find($this->meta['source_id']) : null;
            $this->savedSource = $saved;

            if (is_array($old)) {
                $mapping = $importer->mappingFromColumns($old);
            }
            elseif ($saved && $saved->mapping && !$importer->missingRequired($saved->mapping)) {
                $mapping = $saved->mapping;
            }
            else {
                $mapping = $importer->guessMapping($table['headers']);
            }

            $this->mappingData = $mapping;
            $this->missingRequired = array_map(fn($id) => __('databackd-data-common.fields.' . $id), $importer->missingRequired($mapping));
        }
        elseif ($token) {
            session()->now('error', __('databackd-data-sources.errors.stash_expired'));
        }

        return view('databackd.data.sources.mapping', $this->data);
    }

    /** AJAX: validate the first rows with the chosen mapping. */
    public function preview(Request $request): JsonResponse
    {
        $stash = $this->loadStash((string)$request->input('token'));

        if (!$stash) {
            return response()->json(Reply::error(__('databackd-data-sources.errors.stash_expired')), 422);
        }

        $importer = $this->importer();
        $table = $importer->readCsvString($stash['csv']);
        $mapping = $importer->mappingFromColumns((array)$request->input('columns', []));
        $missing = $importer->missingRequired($mapping);
        $options = [
            'entity_id' => $this->validEntityId($request->input('entity_id')),
            'create_missing_dimensions' => $request->boolean('create_missing_dimensions'),
            'decimals' => $importer->detectDecimalSeparators($table['rows']), // AFV (S12): the separators of the whole sheet, as import() uses them
            'lines' => $table['lines'] ?? [], // AFV (S12): row numbers as in the sheet
        ];

        $rows = [];
        $okCount = 0;
        $accounts = FinAccount::pluck('name', 'id');
        $preview = $importer->preview($table['rows'], $mapping, $options, 10);
        // AFV (S12): rows of the sheet that are already entries - a second import of the same data doubles the figures
        $duplicates = $missing ? null : $importer->existingMatches($table['rows'], $mapping, $options, 50);

        foreach ($preview as $row) {
            $data = $row['data'];

            if ($data) {
                $okCount++;
            }

            $rows[] = [
                'row' => $row['row'],
                'ok' => $data !== null,
                'errors' => $row['errors'],
                'cells' => array_map(fn($v) => (string)$v, $row['raw']),
                'date' => $data['entry_date'] ?? null,
                'account' => $data ? ($accounts[$data['account_id']] ?? null) : null,
                'amount' => $data ? number_format($data['amount'], 2, '.', ' ') . ' ' . $data['currency'] : null,
                'kind' => $data ? __('databackd-data-common.kinds.' . $data['kind']) : null,
            ];
        }

        // live preview of the shared P5 mapping partial expects {"html": ...}
        $html = null;

        if (view()->exists('databackd.data.import._preview')) {
            $html = view('databackd.data.import._preview', [
                'preview' => $preview,
                // a saved source that is imported again replaces its previous import: its own rows are not duplicates
                'duplicates' => $this->withoutOwnBatch($duplicates, $stash['meta'], $request->boolean('remember')),
                'mapping' => $mapping,
                'missing' => $missing,
                'fields' => $importer->fields(),
                'headers' => $table['headers'],
                'count' => min(10, count($table['rows'])),
            ])->render();
        }

        return response()->json([
            'status' => 'success',
            'html' => $html,
            'missing' => array_map(fn($id) => __('databackd-data-common.fields.' . $id), $missing),
            'total' => count($table['rows']),
            'rows' => $rows,
            'ok' => $okCount,
        ]);
    }

    /** Step 4: import all rows. */
    public function import(Request $request): RedirectResponse
    {
        $token = (string)$request->input('token');
        $stash = $this->loadStash($token);

        if (!$stash) {
            return redirect()->route('databackd.data.sources.index')->with('error', __('databackd-data-sources.errors.stash_expired'));
        }

        $importer = $this->importer();
        $mapping = $importer->mappingFromColumns((array)$request->input('columns', []));
        $missing = $importer->missingRequired($mapping);

        if ($missing) {
            return redirect()->route('databackd.data.sources.mapping', $token)->withInput()->with('error', __('databackd-data-sources.errors.missing_required', [
                'fields' => implode(', ', array_map(fn($id) => __('databackd-data-common.fields.' . $id), $missing)),
            ]));
        }

        $meta = $stash['meta'];
        $entityId = $this->validEntityId($request->input('entity_id'));
        $create = $request->boolean('create_missing_dimensions');
        $table = $importer->readCsvString($stash['csv']);

        $lock = Cache::lock('databackd-source-import:' . company()->id . ':' . ($meta['source_id'] ?: $token), 600);

        // a double click: the first request is importing (or has imported and removed the stash) - never import twice
        if (!$lock->get()) {
            return redirect()->route('databackd.data.sources.index')->with('error', __('databackd-data-sources.errors.sync_running'));
        }

        if (!$this->loadStash($token)) {
            $lock->release();

            return redirect()->route('databackd.data.sources.index')->with('error', __('databackd-data-sources.errors.already_imported'));
        }

        try {
            return $this->runImport($importer, $table, $mapping, $meta, $entityId, $create, (string)$request->input('name'), $request->boolean('remember'), $token);
        } finally {
            $lock->release();
        }
    }

    /**
     * The import of step 4 (under the lock of the source). With "remember" the source is saved (an already saved source of the same sheet is
     * updated) and its previous import is replaced like a synchronization; without it the rows are a plain additional import.
     */
    protected function runImport(FinEntryImporter $importer, array $table, array $mapping, array $meta, ?int $entityId, bool $create, string $name, bool $remember, string $token): RedirectResponse
    {
        $rows = $table['rows'];

        if ($remember) {
            $source = $this->saveSource($meta, $mapping, $entityId, $name);
        }
        else {
            $source = null;
        }

        $previous = $source && $source->last_batch_id ? FinImportBatch::find($source->last_batch_id) : null;

        @set_time_limit(300);
        $batch = $importer->import($rows, $mapping, [
            'source' => $meta['type'] === 'csv_url' ? 'csv' : 'google_sheets',
            'source_url' => $meta['url'],
            'file_name' => $meta['file_name'],
            'entity_id' => $entityId,
            'create_missing_dimensions' => $create,
            'user_id' => user()->id,
        ] + $this->errorOptions($table));

        // a re-import of a saved source replaces its previous import instead of duplicating the entries (same as sync())
        if ($source && $batch->rows_imported > 0 && $previous && $previous->status !== FinEntryImporter::STATUS_UNDONE) {
            $importer->undo($previous);
        }

        if ($source) {
            $source->last_synced_at = now();
            $source->last_batch_id = $batch->id;
            $source->save();
        }

        $this->forgetStash($token);

        return redirect()->route('databackd.data.sources.result', $batch->id);
    }

    public function result(int $batch)
    {
        $this->batch = FinImportBatch::findOrFail($batch);
        $this->importErrors = array_slice((array)$this->batch->errors, 0, 20);
        $this->errorsTotal = count((array)$this->batch->errors);
        $this->statusLabel = __('databackd-data-common.statuses.' . $this->batch->status);

        return view('databackd.data.sources.result', $this->data);
    }

    // ------------------------------------------------------------------ saved sources

    /** Save the source with its mapping without importing. */
    public function store(Request $request): RedirectResponse
    {
        $token = (string)$request->input('token');
        $stash = $this->loadStash($token);

        if (!$stash) {
            return redirect()->route('databackd.data.sources.index')->with('error', __('databackd-data-sources.errors.stash_expired'));
        }

        $importer = $this->importer();
        $mapping = $importer->mappingFromColumns((array)$request->input('columns', []));
        $missing = $importer->missingRequired($mapping);

        if ($missing) {
            return redirect()->route('databackd.data.sources.mapping', $token)->withInput()->with('error', __('databackd-data-sources.errors.missing_required', [
                'fields' => implode(', ', array_map(fn($id) => __('databackd-data-common.fields.' . $id), $missing)),
            ]));
        }

        $this->saveSource($stash['meta'], $mapping, $this->validEntityId($request->input('entity_id')), (string)$request->input('name'));
        $this->forgetStash($token);

        return redirect()->route('databackd.data.sources.index')->with('success', __('databackd-data-sources.messages.saved'));
    }

    public function destroy(int $source): RedirectResponse
    {
        FinDataSource::findOrFail($source)->delete();

        return redirect()->route('databackd.data.sources.index')->with('success', __('databackd-data-sources.messages.deleted'));
    }

    /** Re-run the import of a saved source with its saved mapping (replaces the entries of its previous import). */
    public function sync(int $source): RedirectResponse
    {
        $source = FinDataSource::findOrFail($source);
        $back = redirect()->route('databackd.data.sources.index');

        if (!in_array($source->type, ['google_sheets', 'csv_url'], true) || !$source->url) {
            return $back->with('error', __('databackd-data-sources.errors.sync_unsupported'));
        }

        // a double click / two users at once would otherwise both import and both replace the same previous batch (duplicates)
        $lock = Cache::lock('databackd-source-import:' . company()->id . ':' . $source->id, 600);

        if (!$lock->get()) {
            return $back->with('error', $source->name . ': ' . __('databackd-data-sources.errors.sync_running'));
        }

        try {
            return $this->runSync($source);
        } finally {
            $lock->release();
        }
    }

    protected function runSync(FinDataSource $source): RedirectResponse
    {
        $back = redirect()->route('databackd.data.sources.index');

        try {
            $reader = app(GoogleSheetsReader::class, ['companyId' => company()->id]);
            // a source saved before the URL rules were tightened is refused here as well
            $reader->assertSourceUrl($source->type, $source->url);
            $csv = $reader->fetchForType($source->type, $source->url, $source->sheet);
        } catch (\RuntimeException $e) {
            $this->logFailedFetch($source->type, $source->url, $e);

            return $back->with('error', $source->name . ': ' . $e->getMessage());
        }

        $importer = $this->importer();
        $table = $importer->readCsvString($csv);
        $mapping = (array)$source->mapping;

        if (!$table['rows']) {
            return $back->with('error', $source->name . ': ' . __('databackd-data-sources.errors.empty_sheet'));
        }

        // The sheet columns changed when a mapped column no longer exists or a required field is not mapped any more.
        $columnCount = count($table['headers']);
        $gone = [];

        foreach ($mapping as $field => $index) {
            if (!is_numeric($index) || (int)$index >= $columnCount) {
                $gone[] = __('databackd-data-common.fields.' . $field);
            }
        }

        $missing = array_map(fn($id) => __('databackd-data-common.fields.' . $id), $importer->missingRequired($mapping));

        if ($gone || $missing) {
            return $back->with('error', $source->name . ': ' . __('databackd-data-sources.errors.columns_changed', [
                'fields' => implode(', ', array_unique(array_merge($gone, $missing))),
            ]));
        }

        @set_time_limit(300);
        $previous = $source->last_batch_id ? FinImportBatch::find($source->last_batch_id) : null;

        $batch = $importer->import($table['rows'], $mapping, [
            'source' => $source->type === 'csv_url' ? 'csv' : 'google_sheets',
            'source_url' => $source->url,
            'file_name' => $this->fileName($source->type, $source->url, $source->sheet),
            'entity_id' => $source->default_entity_id,
            'create_missing_dimensions' => true,
            'user_id' => user()->id,
        ] + $this->errorOptions($table));

        // keep the data in sync instead of duplicating it: the previous import of this source is replaced
        if ($batch->rows_imported > 0 && $previous && $previous->status !== FinEntryImporter::STATUS_UNDONE) {
            $importer->undo($previous);
        }

        $source->last_synced_at = now();
        $source->last_batch_id = $batch->id;
        $source->save();

        return redirect()->route('databackd.data.sources.result', $batch->id);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * AFV (S12): import options for useful errors - the row numbers of the sheet, the cells of the failed rows and the column names
     * (the "rows with errors" download of the history).
     */
    protected function errorOptions(array $table): array
    {
        return [
            'lines' => count($table['lines'] ?? []) === count($table['rows']) ? array_values($table['lines']) : [],
            'keep_values' => true,
            'batch_meta' => ['_headers' => array_map(fn($h) => mb_substr((string)$h, 0, 100), array_slice($table['headers'], 0, FinEntryImporter::MAX_ERROR_VALUE_COLUMNS))],
        ];
    }

    /**
     * AFV (S12): a saved source imported again with "remember" replaces its previous import, so matches with that import are not
     * duplicates; without "remember" (a plain additional import) they are.
     */
    protected function withoutOwnBatch(?array $duplicates, array $meta, bool $remember): ?array
    {
        if (!$duplicates || !$remember || empty($meta['source_id'])) {
            return $duplicates;
        }

        $own = FinDataSource::whereKey($meta['source_id'])->value('last_batch_id');

        if ($own && collect($duplicates['batches'])->pluck('id')->contains((int)$own)) {
            return null; // the matching rows are (at least partly) the ones that will be replaced
        }

        return $duplicates;
    }

    protected function importer(): FinEntryImporter
    {
        return new FinEntryImporter(company()->id);
    }

    /** Audit trail for refused/failed downloads (host only: the URL itself may contain a secret id). */
    protected function logFailedFetch(string $type, string $url, \RuntimeException $e): void
    {
        $host = (string)parse_url($url, PHP_URL_HOST);

        Log::warning('Anagami Finance View data source download refused or failed', [
            'company_id' => company()->id,
            'user_id' => user()->id,
            'type' => $type,
            'host' => Str::limit(preg_replace('~[^\x21-\x7e]~', '?', $host), 100, ''),
            'reason' => Str::limit($e->getMessage(), 150),
        ]);
    }

    protected function entities()
    {
        return FinEntity::where('is_active', true)->orderBy('sort')->orderBy('name')->get();
    }

    protected function validEntityId($id): ?int
    {
        return $id && FinEntity::whereKey((int)$id)->exists() ? (int)$id : null;
    }

    protected function attributes(): array
    {
        return [
            'type' => __('databackd-data-sources.form.type'),
            'url' => __('databackd-data-sources.form.url'),
            'sheet' => __('databackd-data-sources.form.sheet'),
            'name' => __('databackd-data-sources.form.name'),
            'default_entity_id' => __('databackd-data-sources.form.entity'),
        ];
    }

    protected function fileName(string $type, string $url, ?string $sheet): string
    {
        if ($type === 'csv_url') {
            return mb_substr(basename((string)parse_url($url, PHP_URL_PATH)) ?: $url, 0, 190);
        }

        try {
            $id = app(GoogleSheetsReader::class)->parseUrl($url)['id'];
        } catch (\RuntimeException $e) {
            $id = $url;
        }

        return mb_substr('Google Sheet ' . Str::limit($id, 12, '') . ($sheet ? ' (gid ' . $sheet . ')' : ''), 0, 190);
    }

    /**
     * The saved source for the same sheet: a Google Sheet is compared by its CSV export URL (so /edit#gid=0, ?usp=sharing and the bare id
     * are the same sheet), a CSV URL by its address.
     */
    protected function findSavedSource(string $type, string $url, ?string $sheet): ?FinDataSource
    {
        $reader = app(GoogleSheetsReader::class);
        $key = fn(?string $url, ?string $sheet) => $type === 'google_sheets' ? $reader->exportUrl((string)$url, $sheet) : trim((string)$url);

        try {
            $wanted = $key($url, $sheet);
        } catch (\RuntimeException $e) {
            return null;
        }

        foreach (FinDataSource::where('type', $type)->orderBy('id')->get() as $source) {
            try {
                if ($key($source->url, $source->sheet) === $wanted) {
                    return $source;
                }
            } catch (\RuntimeException $e) {
                // a source saved under older URL rules: not comparable
            }
        }

        return null;
    }

    /** Create or update the FinDataSource of the current import flow. */
    protected function saveSource(array $meta, array $mapping, ?int $entityId, string $name): FinDataSource
    {
        $source = $meta['source_id'] ? FinDataSource::find($meta['source_id']) : null;
        $source = $source ?: new FinDataSource();

        if (!$source->exists) {
            $source->company_id = company()->id;
            $source->created_by = user()->id;
        }

        $name = trim($name) ?: $meta['name'];
        $source->name = mb_substr($name ?: $meta['file_name'], 0, 190);
        $source->type = $meta['type'];
        $source->url = $meta['url'];
        $source->sheet = $meta['sheet'];
        $source->mapping = $mapping;
        $source->default_entity_id = $entityId;
        $source->save();

        return $source;
    }

    // ------------------------------------------------------------------ stash (downloaded CSV between the steps)

    protected function stashDir(): string
    {
        $dir = storage_path('app/databackd-imports/' . company()->id);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    protected function stash(string $csv, array $meta): string
    {
        $dir = $this->stashDir();

        // remove abandoned files older than a day
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }

        $token = (string)Str::uuid();
        file_put_contents($dir . '/' . $token . '.csv', $csv);
        file_put_contents($dir . '/' . $token . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE));

        return $token;
    }

    /** @return array{csv: string, meta: array}|null */
    protected function loadStash(string $token): ?array
    {
        if (!Str::isUuid($token)) {
            return null;
        }

        $base = $this->stashDir() . '/' . $token;

        if (!is_file($base . '.csv') || !is_file($base . '.json')) {
            return null;
        }

        $meta = (array)json_decode((string)file_get_contents($base . '.json'), true);

        // a stash made before the URL rules were tightened (or edited on disk) must not be stored/imported under a disallowed URL
        try {
            app(GoogleSheetsReader::class)->assertSourceUrl((string)($meta['type'] ?? ''), (string)($meta['url'] ?? ''));
        } catch (\RuntimeException $e) {
            return null;
        }

        return [
            'csv' => (string)file_get_contents($base . '.csv'),
            'meta' => $meta,
        ];
    }

    protected function forgetStash(string $token): void
    {
        if (Str::isUuid($token)) {
            $base = $this->stashDir() . '/' . $token;
            @unlink($base . '.csv');
            @unlink($base . '.json');
        }
    }

}
