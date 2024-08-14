<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinApiToken;
use App\Models\Databackd\FinEntity;
use App\Services\Databackd\Data\FinEntryImporter;
use App\Services\Databackd\Data\ImportTemplates;
use Illuminate\Support\Facades\Route;

class IntegrationController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-integrations.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index()
    {
        $importer = new FinEntryImporter(company()->id);

        $this->cards = $this->activeCards();
        $this->plannedCards = $this->plannedCards();
        $this->platformCards = $this->platformCards(); // AFV (S8)
        // the sync routes are behind the databackd_data module - without it the button would only answer 403
        // audit 2026-10-30 (G11): the sync reads AFV invoices/expenses - at least one source module must be on
        $this->hasSync = Route::has('databackd.data.sync.run') && databackd_module('databackd_data')
            && (in_array('invoices', user_modules()) || in_array('expenses', user_modules()));
        $this->canManageTokens = in_array('admin', user_roles());
        $this->tokens = FinApiToken::orderByDesc('id')->get();
        $this->fields = $importer->fields();

        $this->endpoint = url('/api/databackd/fin-entries');
        $this->bodyExample = $this->bodyExample();
        $this->curlExample = $this->curlExample($this->endpoint, $this->bodyExample);
        $this->responseExample = $this->responseExample();

        // the limits shown in the documentation are the ones the API enforces (config/databackd-api.php)
        $this->apiLimits = [
            'max' => (int)config('databackd-api.max_records', 1000),
            'per_minute' => (int)config('databackd-api.rate.per_token_per_minute', 60),
            'per_hour' => (int)config('databackd-api.rate.records_per_token_per_hour', 50000),
            'bytes' => (int)config('databackd-api.max_body_bytes', 2 * 1024 * 1024),
            // AFV (S12): the other limits of FinEntryApiController / FinApiTokenAuth and the "api" throttle (RouteServiceProvider)
            'dimensions' => (int)config('databackd-api.max_new_dimensions', 200),
            'failed_ip' => (int)config('databackd-api.rate.failed_per_ip_per_minute', 10),
            'per_ip' => 60,
        ];

        return view('databackd.data.integrations.index', $this->data);
    }

    /**
     * `locked`: the screen belongs to a package module the company does not have (AFV S12 - the link would only answer 403).
     *
     * @return array<int, array{key: string, icon: string, color: string, url: ?string, anchor: bool, locked: bool}>
     */
    protected function activeCards(): array
    {
        $dataModule = databackd_module('databackd_data');
        $link = fn(string $route, bool $allowed = true) => $allowed && Route::has($route) ? route($route) : null;

        return [
            ['key' => 'excel', 'icon' => 'file-excel', 'color' => '#1d7a46', 'url' => $link('databackd.data.import.index', $dataModule), 'anchor' => false, 'locked' => !$dataModule],
            ['key' => 'csv', 'icon' => 'file-csv', 'color' => '#3f7cc4', 'url' => $link('databackd.data.import.index', $dataModule), 'anchor' => false, 'locked' => !$dataModule],
            // the sources screen is behind databackd_integrations - the module of this page
            ['key' => 'google_sheets', 'icon' => 'table', 'color' => '#0f9d58', 'url' => $link('databackd.data.sources.index'), 'anchor' => false, 'locked' => false],
            ['key' => 'api', 'icon' => 'plug', 'color' => '#6c4fd3', 'url' => '#api-access', 'anchor' => true, 'locked' => false],
        ];
    }

    /**
     * @return array<int, array{key: string, icon: string}>
     */
    protected function plannedCards(): array
    {
        $cards = [
            ['key' => 'microinvest', 'icon' => 'calculator'],
            ['key' => 'ajur', 'icon' => 'calculator'],
            ['key' => 'plus_minus', 'icon' => 'calculator'],
            ['key' => 'business_navigator', 'icon' => 'compass'],
            ['key' => 'salesforce', 'icon' => 'cloud'],
            ['key' => 'quickbooks', 'icon' => 'book'],
            ['key' => 'xero', 'icon' => 'book-open'],
            ['key' => 'bank_statements', 'icon' => 'university'],
        ];

        // AFV (S8): the accounting programs have import templates now and bank statements have their own screen (when it exists),
        // so they are shown as active cards (platformCards()) instead of "soon"
        $active = array_column($this->platformCards(), 'key');

        return array_values(array_filter($cards, fn($card) => !in_array($card['key'], $active, true)));
    }

    /**
     * AFV (S8): active cards of the accounting programs (import wizard with a preselected template) and of the bank statements.
     *
     * Both screens are behind the databackd_data module: without it a card is shown locked, without a link (AFV S12).
     *
     * @return array<int, array{key: string, icon: string, color: string, url: ?string, locked: bool}>
     */
    protected function platformCards(): array
    {
        $cards = [];
        $dataModule = databackd_module('databackd_data');

        if (Route::has('databackd.data.import.index')) {
            $templates = new ImportTemplates(company()->id);

            foreach ($templates->platforms() as $platform) {
                $cards[] = [
                    'key' => $platform['id'],
                    'icon' => $platform['icon'],
                    'color' => $platform['color'],
                    'url' => $dataModule ? route('databackd.data.import.index', ['template' => $platform['default_template']]) : null,
                    'locked' => !$dataModule,
                ];
            }
        }

        if (Route::has('databackd.gaps.bank-import.index')) {
            $cards[] = ['key' => 'bank_statements', 'icon' => 'university', 'color' => '#0b7285',
                'url' => $dataModule ? route('databackd.gaps.bank-import.index') : null, 'locked' => !$dataModule];
        }

        return $cards;
    }

    protected function bodyExample(): string
    {
        $entity = FinEntity::orderBy('sort')->orderBy('id')->value('name');

        $first = [
            'entry_date' => '2026-10-31',
            'account_code' => '703',
            'amount' => 1250.5,
        ];

        if ($entity) {
            $first['entity'] = $entity;
        }

        $first += [
            'counterparty_name' => 'Клиент ООД',
            'document_no' => 'INV-2026-1001',
            'description' => 'Продажба на услуги',
        ];

        $second = [
            'entry_date' => '2026-10-31',
            'account_code' => '602',
            'amount' => 480,
            'document_no' => 'EXP-2026-0412',
            'description' => 'Наем на офис',
        ];

        return json_encode([$first, $second], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function curlExample(string $endpoint, string $body): string
    {
        $body = str_replace("'", "'\\''", $body);

        return "curl -X POST \"{$endpoint}\" \\\n"
            . "  -H \"Authorization: Bearer " . __('databackd-data-integrations.docs.token_placeholder') . "\" \\\n"
            . "  -H \"Content-Type: application/json\" \\\n"
            . "  -H \"Accept: application/json\" \\\n"
            . "  -H 'Idempotency-Key: \"8e03978e-40d5-43e8-bc93-6894a57f9324\"' \\\n"
            . "  -d '{$body}'";
    }

    protected function responseExample(): string
    {
        return json_encode([
            'batch_id' => 42,
            'status' => 'partial',
            'imported' => 1,
            'failed' => 1,
            'errors' => [
                ['row' => 2, 'messages' => [__('databackd-data-common.errors.account_unknown', ['value' => '999'])]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

}
