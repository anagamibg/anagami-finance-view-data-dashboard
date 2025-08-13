<?php

namespace App\Console\Commands\Databackd;

use App\Models\Company;
use App\Services\Databackd\Gaps\Alerts\AlertChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * databackd:check-alerts - checks the active alert rules (thresholds) of every company that has the
 * "databackd_analytics" module and notifies the company admins in the application and by e-mail.
 * Runs without a logged in user. A failing rule or company does not stop the others.
 *
 * Schedule: hourly (app/Console/Kernel.php): $schedule->command('databackd:check-alerts')->hourly()->withoutOverlapping();
 */
class CheckAlerts extends Command
{

    protected $signature = 'databackd:check-alerts
                            {--dry-run : Only evaluate the rules; no alerts are written and nobody is notified}
                            {--company= : Only this company (id or part of the name)}';

    protected $description = 'Check the alert rules (ratios, budget deviation, cash, receivables) and notify about the fired ones';

    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $originalLocale = App::getLocale();

        try {
            $query = Company::query()->where('status', 'active')->orderBy('id');
            $filter = trim((string)$this->option('company'));

            if ($filter !== '') {
                ctype_digit($filter)
                    ? $query->where('id', (int)$filter)
                    : $query->where('company_name', 'like', '%' . $filter . '%');
            }

            $companies = $query->get();

            if ($companies->isEmpty()) {
                $this->warn($this->t('no_company'));

                return self::SUCCESS;
            }

            $checker = new AlertChecker();
            $total = ['companies' => 0, 'checked' => 0, 'triggered' => 0, 'fired' => 0, 'recovered' => 0, 'no_data' => 0, 'errors' => 0];

            foreach ($companies as $company) {
                if (!databackd_company_module((int)$company->id, 'databackd_analytics')) {
                    $this->line($this->t('skipped_module', ['id' => $company->id, 'name' => $company->company_name]));
                    continue;
                }

                // the messages, the notifications and the output of this company are in its language
                App::setLocale($company->locale ?: config('app.locale'));

                try {
                    $summary = $checker->checkCompany((int)$company->id, $dryRun);

                    if ($summary['checked'] === 0) {
                        continue;
                    }

                    $total['companies']++;

                    foreach (['checked', 'triggered', 'fired', 'recovered', 'no_data', 'errors'] as $key) {
                        $total[$key] += $summary[$key];
                    }

                    $this->info($this->t($dryRun ? 'company_dry_run' : 'company', [
                        'id' => $company->id, 'name' => $company->company_name, 'checked' => $summary['checked'],
                        'triggered' => $summary['triggered'], 'fired' => $summary['fired'], 'recovered' => $summary['recovered'],
                        'no_data' => $summary['no_data'], 'errors' => $summary['errors'],
                    ]));

                    foreach ($summary['results'] as $result) {
                        $this->line('  ' . $this->resultLine($result));
                    }
                } catch (\Throwable $e) {
                    $total['errors']++;
                    $this->error($this->t('company_failed', ['id' => $company->id, 'name' => $company->company_name, 'error' => $e->getMessage()]));
                    Log::error('databackd:check-alerts company #' . $company->id . ': ' . $e->getMessage(), ['exception' => $e]);
                } finally {
                    App::setLocale($originalLocale);
                }
            }

            $this->line($this->t($dryRun ? 'summary_dry_run' : 'summary', $total));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            Log::error('databackd:check-alerts: ' . $e->getMessage(), ['exception' => $e]);

            return self::FAILURE;
        } finally {
            App::setLocale($originalLocale);
        }
    }

    protected function resultLine(array $result): string
    {
        $label = $result['status'] === 'triggered' || $result['action'] === 'recovered'
            ? __('databackd-gaps-alerts.actions.' . $result['action'])
            : __('databackd-gaps-alerts.states.' . $result['status']);

        $detail = match ($result['status']) {
            'triggered' => $result['message'],
            'ok' => $result['value_text'] . ' / ' . $result['threshold_text'],
            'no_data' => __('databackd-gaps-alerts.reasons.' . ($result['reason'] ?: 'no_entries')),
            default => $result['error'] ?: __('databackd-gaps-alerts.reasons.' . ($result['reason'] ?: 'exception')),
        };

        $channels = '';

        if ($result['event_id']) {
            $channels = ' [' . ($result['notified_app'] ? 'app' : '-') . '/' . ($result['notified_email'] ? 'mail' : '-') . ']';
        }

        return '#' . $result['rule_id'] . ' ' . $result['name'] . ' — ' . $label . ': ' . $detail . $channels;
    }

    protected function t(string $key, array $replace = []): string
    {
        return __('databackd-gaps-alerts.command.' . $key, $replace);
    }

}
