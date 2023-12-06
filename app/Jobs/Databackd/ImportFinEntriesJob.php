<?php

namespace App\Jobs\Databackd;

use App\Models\Databackd\FinImportBatch;
use App\Services\Databackd\Data\FinEntryImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Imports one chunk (up to 500 rows) of a file into fin_entries as part of an existing fin_import_batches record.
 *
 * Chunks are dispatched as a chain, so they run one after another and the counters of the batch never race.
 * The last chunk finalizes the batch (status completed / partial / failed) and removes the uploaded temp file.
 */
class ImportFinEntriesJob implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 600;

    /**
     * @param array<int,array<int,mixed>> $rows chunk of data rows (lists indexed by column)
     * @param array<string,int> $mapping canonical mapping [fieldId => columnIndex]
     * @param array<string,mixed> $options FinEntryImporter options (entity_id, create_missing_dimensions, currency)
     * @param int $rowOffset number of data rows already processed by earlier chunks
     * @param string|null $cleanupPath temp file to delete when this chunk is the last one (or when the import fails)
     */
    public function __construct(
        public int $companyId,
        public int $batchId,
        public array $rows,
        public array $mapping,
        public array $options,
        public int $rowOffset,
        public bool $isLast,
        public ?string $cleanupPath = null,
        public ?string $locale = null
    )
    {
    }

    public function handle(): void
    {
        if ($this->locale) {
            app()->setLocale($this->locale);
        }

        $importer = new FinEntryImporter($this->companyId);

        $importer->import($this->rows, $this->mapping, $this->options + [
            'batch_id' => $this->batchId,
            'row_offset' => $this->rowOffset,
            'finalize' => $this->isLast,
        ]);

        if ($this->isLast) {
            $this->deleteTempFile();
        }
    }

    public function failed(Throwable $exception): void
    {
        $batch = FinImportBatch::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->find($this->batchId);

        if ($batch) {
            $errors = $batch->errors ?: [];
            $errors[] = [
                'row' => 0,
                'messages' => [$this->translate('job_failed', $exception->getMessage())],
            ];

            $batch->errors = $errors;
            $batch->status = FinEntryImporter::STATUS_FAILED;
            $batch->save();
        }

        $this->deleteTempFile();
    }

    protected function translate(string $key, string $message): string
    {
        if ($this->locale) {
            app()->setLocale($this->locale);
        }

        return __('databackd-data-import.messages.' . $key, ['message' => $message]);
    }

    protected function deleteTempFile(): void
    {
        if ($this->cleanupPath && is_file($this->cleanupPath)) {
            @unlink($this->cleanupPath);
        }
    }

}
