<?php

namespace App\Http\Controllers\Databackd\Data;

use App\DataTables\Databackd\Data\FinImportBatchesDataTable;
use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinImportBatch;
use App\Models\User;
use App\Services\Databackd\Data\FinEntryImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

/**
 * Import history: list of fin_import_batches, batch detail with errors, undo and errors CSV.
 */
class FinImportBatchController extends AccountBaseController
{

    use NeutralizesFormulas;

    /** Max error rows rendered in the detail table (all stored errors are available in the CSV export). */
    const ERRORS_ON_PAGE = 200;

    /** Max sample entries on the detail page. */
    const SAMPLE_ENTRIES = 10;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-batches.title');
            abort_403(in_array('client', user_roles()));
            return $next($request);
        });
    }

    public function index(FinImportBatchesDataTable $dataTable)
    {
        if (!request()->ajax()) {
            $this->totalBatches = FinImportBatch::count();
            $this->importedRows = (int)FinImportBatch::where('status', '!=', FinEntryImporter::STATUS_UNDONE)->sum('rows_imported');
            $this->failedRows = (int)FinImportBatch::sum('rows_failed');
            $this->undoneBatches = FinImportBatch::where('status', FinEntryImporter::STATUS_UNDONE)->count();
            // AFV (S12): the bank statements (S8) are in the history too and must be selectable in the filter
            $this->sources = ['excel', 'csv', 'google_sheets', 'api', 'mt940', 'camt053'];
            $this->statuses = FinEntryImporter::STATUSES;
        }

        return $dataTable->render('databackd.data.batches.index', $this->data);
    }

    public function show($id)
    {
        $batch = FinImportBatch::findOrFail($id);

        $this->batch = $batch;
        $this->pageTitle = __('databackd-data-batches.show_title', ['id' => $batch->id]);
        $this->importUser = $batch->created_by ? User::withoutGlobalScopes()->find($batch->created_by) : null;

        $this->entriesCount = $batch->entries()->count();
        $this->sampleEntries = $batch->entries()
            ->with(['account:id,code,name,type', 'entity:id,name'])
            ->orderBy('id')
            ->limit(self::SAMPLE_ENTRIES)
            ->get();

        $errors = collect($batch->errors ?: [])->filter(fn($e) => is_array($e) && isset($e['row']))->values();
        $this->errorsTotal = $errors->count();
        $this->errorRows = $errors->take(self::ERRORS_ON_PAGE);

        $this->mappingRows = $this->mappingRows($batch->mapping);
        $this->canUndo = self::canUndo($batch, $this->entriesCount);
        $this->entriesUrl = Route::has('databackd.data.entries.index')
            ? route('databackd.data.entries.index', ['batch_id' => $batch->id])
            : null;

        return view('databackd.data.batches.show', $this->data);
    }

    public function undo(Request $request, $id)
    {
        $batch = FinImportBatch::findOrFail($id);

        if ($batch->status === FinEntryImporter::STATUS_UNDONE) {
            return Reply::error(__('databackd-data-batches.undo.already_undone'));
        }

        if (in_array($batch->status, [FinEntryImporter::STATUS_PROCESSING, FinEntryImporter::STATUS_PENDING], true)) {
            return Reply::error(__('databackd-data-batches.undo.processing'));
        }

        if (!self::canUndo($batch, $batch->entries()->count())) {
            return Reply::error(__('databackd-data-batches.undo.no_entries'));
        }

        $deleted = (new FinEntryImporter(company()->id))->undo($batch);

        return Reply::success(__('databackd-data-batches.undo.success', ['count' => $deleted]));
    }

    /**
     * neutralizeFormula() only looks at the first character; the raw cells of the uploaded file can also hide a formula after a
     * comma or semicolon (Excel with a "," list separator splits "x,=1+1" into two cells), so such text is prefixed as well.
     */
    protected static function neutralizeCsvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && $value[0] !== "'" && preg_match('/[,;\t]\s*[=+\-@]/u', $value)) {
            return "'" . $value;
        }

        return self::neutralizeFormula($value);
    }

    public function errorsExport($id)
    {
        $batch = FinImportBatch::findOrFail($id);
        $errors = $batch->errors ?: [];
        $name = __('databackd-data-batches.errors.export_name', ['id' => $batch->id]);

        // AFV (S12): when the batch kept the cells of the failed rows, the download is the failed rows themselves (original columns
        // first, then the row number and the reasons) - the user fixes them and imports the file again
        $headers = array_values(array_filter((array)(($batch->mapping ?? [])['_headers'] ?? []), 'is_scalar'));
        $withValues = $headers && collect($errors)->contains(fn($e) => is_array($e) && isset($e['values']));

        return response()->streamDownload(function () use ($errors, $headers, $withValues) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, so Excel opens Cyrillic correctly

            if ($withValues) {
                fputcsv($out, array_merge(array_map(fn($h) => self::neutralizeFormula((string)$h), $headers), [__('databackd-data-batches.csv.file_row'), __('databackd-data-batches.csv.reasons')]), ';');

                foreach ($errors as $error) {
                    if (!is_array($error)) {
                        continue;
                    }

                    $values = array_pad(array_slice(array_values((array)($error['values'] ?? [])), 0, count($headers)), count($headers), null);
                    // a plain number ("-600.00") cannot be a formula: it stays as it was, so the fixed file imports without changes
                    $values = array_map(fn($v) => is_string($v) && is_numeric($v) ? $v : self::neutralizeCsvCell($v), $values);

                    fputcsv($out, array_merge($values, [
                        (string)($error['row'] ?? ''),
                        self::neutralizeFormula(implode(' | ', (array)($error['messages'] ?? []))),
                    ]), ';');
                }

                fclose($out);

                return;
            }

            fputcsv($out, [__('databackd-data-batches.csv.row'), __('databackd-data-batches.csv.reasons')], ';');

            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }

                // S6/P4: a validation message can quote the offending raw cell value from the uploaded file
                // (e.g. "Невалидна дата: <value>" - see FinEntryImporter::validateRow()), so it is neutralized
                // here against CSV injection - this is a plain CSV with no cell-type metadata, so Excel's own
                // import heuristics (not PhpSpreadsheet's) decide whether a cell is a formula, purely from its
                // first character.
                fputcsv($out, [
                    self::neutralizeFormula((string)($error['row'] ?? '')),
                    self::neutralizeFormula(implode(' | ', (array)($error['messages'] ?? []))),
                ], ';');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * An import can be undone when it is finished (not undone/processing/pending) and still has entries.
     * When $entriesCount is null the imported-rows counter is used instead of counting entries (cheap, for lists).
     */
    public static function canUndo(FinImportBatch $batch, ?int $entriesCount = null): bool
    {
        if (in_array($batch->status, [FinEntryImporter::STATUS_UNDONE, FinEntryImporter::STATUS_PROCESSING, FinEntryImporter::STATUS_PENDING], true)) {
            return false;
        }

        return ($entriesCount ?? (int)$batch->rows_imported) > 0;
    }

    /**
     * Mapping ([fieldId => columnIndex]) as display rows sorted by column: letter ("A", "B", ...), number and field label.
     */
    protected function mappingRows($mapping): array
    {
        $rows = [];
        $mapping = (array)$mapping;
        $headers = array_values((array)($mapping['_headers'] ?? []));

        foreach ((array)$mapping as $field => $column) {
            // AFV (S12): only "field => column" pairs - not the stored meta (_headers, _file_sha256) or the settings of a bank statement
            if (str_starts_with((string)$field, '_') || is_array($column) || !Lang::has('databackd-data-common.fields.' . $field)) {
                continue;
            }

            $key = 'databackd-data-common.fields.' . $field;
            $isIndex = is_numeric($column);

            $rows[] = [
                'header' => $isIndex ? ($headers[(int)$column] ?? null) : null, // AFV (S12): the column name in the file
                'sort' => $isIndex ? (int)$column : PHP_INT_MAX,
                'column' => $isIndex ? __('databackd-data-batches.meta.column', ['letter' => $this->columnLetter((int)$column)]) : (string)$column,
                'label' => Lang::has($key) ? __($key) : (string)$field,
            ];
        }

        usort($rows, fn($a, $b) => $a['sort'] <=> $b['sort']);

        return $rows;
    }

    protected function columnLetter(int $index): string
    {
        $letter = '';
        $n = $index + 1;

        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $n = intdiv($n - 1, 26);
        }

        return $letter;
    }

}
