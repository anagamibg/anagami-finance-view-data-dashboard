<?php

namespace App\Http\Controllers\Databackd\Data\Api;

use Illuminate\Routing\Controller;
use App\Models\Company;
use App\Services\Databackd\Data\FinApiAudit;
use App\Services\Databackd\Data\FinEntryImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/databackd/fin-entries - imports a JSON array of records (see FinEntryImporter::fields()).
 * Error row numbers in the response are the 1-based positions in the submitted array.
 *
 * Security (S6/P6): runs behind FinApiGate (HTTPS, method, Content-Length) and FinApiTokenAuth (token, rate limits). Here:
 *  - Content-Type must be JSON (415), the received body is size-checked again (413), JSON depth is limited;
 *  - the company, the author and the source of every entry come from the token / the code, never from the request body
 *    (company_id, created_by, source, import_batch_id ... in a record are ignored: only FinEntryImporter::fields() are read);
 *  - every record is type-checked by FinEntryImporter::importRecords() and every account / legal entity / dimension is looked up
 *    in the token's company only;
 *  - at most config('databackd-api.max_records') records per request, a records-per-hour budget per token, a cap on dimension values
 *    created per request;
 *  - the import runs in one transaction: a failure leaves nothing half-written; the client never sees an exception message;
 *  - optional "Idempotency-Key" header (draft-ietf-httpapi-idempotency-key-header): a retry with the same key and the same body
 *    gets the stored answer of the first call instead of importing the records again (same key + other body = 422, while the
 *    first call still runs = 409, malformed key = 400). Keys are kept per token for IDEMPOTENCY_TTL seconds.
 */
class FinEntryApiController extends Controller
{

    public const MAX_RECORDS = 1000;

    /** How long (seconds) the answer to an Idempotency-Key is kept. */
    public const IDEMPOTENCY_TTL = 86400;

    public function store(Request $request): JsonResponse
    {
        $token = $request->attributes->get('fin_api_token');
        $companyId = (int)$request->attributes->get('fin_company_id');
        $context = ['token_id' => $token->id, 'company_id' => $companyId];

        $company = Company::find($companyId);

        if ($company && $company->locale) {
            App::setLocale($company->locale);
        }

        if (!$request->isJson()) {
            FinApiAudit::failure('unsupported_media_type', $request, $context);

            return $this->json(['message' => 'The request must be sent with Content-Type: application/json.'], 415);
        }

        $raw = $request->getContent();

        if (strlen($raw) > (int)config('databackd-api.max_body_bytes', 2 * 1024 * 1024)) {
            FinApiAudit::failure('body_too_large', $request, $context + ['bytes' => strlen($raw)]);

            return $this->json(['message' => 'The request body is too large.'], 413);
        }

        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey === false) {
            FinApiAudit::failure('idempotency_key_invalid', $request, $context);

            return $this->json(['message' => 'Invalid Idempotency-Key header (1-255 printable ASCII characters, e.g. a UUID).'], 400);
        }

        if ($idempotencyKey === null) {
            return $this->process($request, $raw, $token, $companyId, $context);
        }

        // the key is scoped to the token; the fingerprint covers the body and the query string (create_missing_dimensions)
        $cacheKey = 'databackd-fin-api:idem:' . $token->id . ':' . hash('sha256', $idempotencyKey);
        $fingerprint = hash('sha256', $request->getQueryString() . "\n" . $raw);
        $store = Cache::getStore();
        $lock = $store instanceof \Illuminate\Contracts\Cache\LockProvider ? $store->lock($cacheKey . ':lock', 600) : null;

        if ($lock && !$lock->get()) {
            FinApiAudit::failure('idempotency_conflict', $request, $context);

            return $this->json(['message' => 'A request with this Idempotency-Key is still being processed.'], 409);
        }

        try {
            $stored = Cache::get($cacheKey);

            if (is_array($stored)) {
                if (!hash_equals((string)$stored['fingerprint'], $fingerprint)) {
                    FinApiAudit::failure('idempotency_mismatch', $request, $context);

                    return $this->json(['message' => 'This Idempotency-Key was already used with a different request.'], 422);
                }

                FinApiAudit::success('import_replayed', $request, $context + ['batch_id' => $stored['body']['batch_id'] ?? null]);

                return $this->json($stored['body'], (int)$stored['status']);
            }

            $response = $this->process($request, $raw, $token, $companyId, $context);

            // only an executed import is remembered; rejected requests changed nothing and may be corrected and resent with the same key
            if (in_array($response->getStatusCode(), [200, 207], true)) {
                Cache::put($cacheKey, ['fingerprint' => $fingerprint, 'status' => $response->getStatusCode(), 'body' => $response->getData(true)], self::IDEMPOTENCY_TTL);
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Validate and import the decoded body (everything after the transport checks of store()).
     */
    protected function process(Request $request, string $raw, $token, int $companyId, array $context): JsonResponse
    {
        // depth 16: a record is 3 levels deep ([ { field } ]), nested values are invalid anyway
        $body = json_decode($raw, true, 16);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
            FinApiAudit::failure('invalid_json', $request, $context);

            return $this->json(['message' => __('databackd-data-integrations.api.invalid_json')], 422);
        }

        $records = $this->extractRecords($body);

        if (!$records) {
            FinApiAudit::failure('no_records', $request, $context);

            return $this->json(['message' => __('databackd-data-integrations.api.no_records')], 422);
        }

        $maxRecords = (int)config('databackd-api.max_records', self::MAX_RECORDS);

        if (count($records) > $maxRecords) {
            FinApiAudit::failure('too_many_records', $request, $context + ['records' => count($records)]);

            return $this->json(['message' => __('databackd-data-integrations.api.too_many', ['max' => $maxRecords])], 422);
        }

        $createMissing = filter_var(
            $request->query('create_missing_dimensions', $body['create_missing_dimensions'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($createMissing && ($newDimensions = $this->distinctDimensionValues($records)) > (int)config('databackd-api.max_new_dimensions', 200)) {
            FinApiAudit::failure('too_many_dimensions', $request, $context + ['distinct_values' => $newDimensions]);

            return $this->json(['message' => 'Too many distinct dimension values in one request (max ' . (int)config('databackd-api.max_new_dimensions', 200) . ').'], 422);
        }

        // records-per-hour budget of the token (a leaked token cannot flood the database)
        $budgetKey = 'databackd-fin-api:records:' . $token->id;
        $budget = (int)config('databackd-api.rate.records_per_token_per_hour', 50000);

        if (RateLimiter::attempts($budgetKey) + count($records) > $budget) {
            FinApiAudit::failure('records_budget', $request, $context + ['records' => count($records)]);

            return $this->json(['message' => 'Too Many Requests: the hourly record limit of this token is used up.'], 429, ['Retry-After' => max(1, RateLimiter::availableIn($budgetKey))]);
        }

        try {
            $batch = DB::transaction(fn() => (new FinEntryImporter($companyId))->importRecords($records, [
                'file_name' => 'API: ' . $token->name,
                'create_missing_dimensions' => $createMissing,
                'user_id' => $token->created_by,
            ]));
        } catch (\Throwable $e) {
            report($e);
            FinApiAudit::failure('import_error', $request, $context + ['records' => count($records), 'exception' => get_class($e)]);

            // no exception message / trace / SQL to the client
            return $this->json(['message' => 'The request could not be processed. Nothing was imported.'], 500);
        }

        RateLimiter::increment($budgetKey, 3600, count($records));

        // importRecords reports file rows (position + 2, header = 1); the API reports the position in the array (1-based)
        $errors = [];

        foreach ($batch->errors ?: [] as $error) {
            $errors[] = [
                'row' => max(1, (int)$error['row'] - 1),
                'messages' => array_values($error['messages'] ?? []),
            ];
        }

        FinApiAudit::success('import', $request, $context + [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'records' => count($records),
            'imported' => (int)$batch->rows_imported,
            'failed' => (int)$batch->rows_failed,
        ]);

        return $this->json([
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'imported' => (int)$batch->rows_imported,
            'failed' => (int)$batch->rows_failed,
            'errors' => $errors,
        ], $batch->rows_failed > 0 ? 207 : 200);
    }

    /**
     * Number of distinct non-empty dimension values (region, client_segment, product_group, cost_center) in the records.
     */
    protected function distinctDimensionValues(array $records): int
    {
        $distinct = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            foreach (['region', 'client_segment', 'product_group', 'cost_center'] as $type) {
                $value = $record[$type] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $distinct[$type . '|' . mb_strtolower(trim($value))] = true;
                }
            }
        }

        return count($distinct);
    }

    /**
     * Accepts a plain list, {"entries": [...]} or a single record object.
     */
    protected function extractRecords(array $body): array
    {
        if (array_is_list($body)) {
            return $body;
        }

        if (isset($body['entries']) && is_array($body['entries'])) {
            return array_is_list($body['entries']) ? $body['entries'] : [$body['entries']];
        }

        if (isset($body['entry_date']) || isset($body['account_code']) || isset($body['amount'])) {
            return [$body];
        }

        return [];
    }

    /**
     * The Idempotency-Key header: null = not sent, false = malformed. The draft defines it as a structured-field string
     * ("..."); an unquoted token is accepted too.
     */
    protected function idempotencyKey(Request $request): string|false|null
    {
        $value = $request->headers->get('Idempotency-Key');

        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return preg_match('/^[\x21\x23-\x5B\x5D-\x7E][\x20\x21\x23-\x5B\x5D-\x7E]{0,254}$/', $value) === 1 ? $value : false;
    }

    protected function json(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, $headers, JSON_UNESCAPED_UNICODE);
    }

}
