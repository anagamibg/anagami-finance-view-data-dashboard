<?php

namespace App\Mail\Databackd;

use App\Models\Company;
use App\Models\Databackd\FinAlertEvent;
use App\Services\Databackd\Gaps\Alerts\AlertText;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Route;

/**
 * E-mail of a fired alert rule (see AlertChecker). Works without a logged in user; the text is rendered in $locale.
 */
class FinAlertMail extends Mailable
{

    public string $messageText;
    public string $severityLabel;
    public string $valueText = '';
    public string $thresholdText = '';
    public string $periodText = '';
    /** @var string[] extra lines (the budget deviation breakdown) */
    public array $lines = [];
    public ?string $openUrl = null;
    public string $checkedAt = '';
    /** Display name of the platform for the footer: APP_NAME is the mode switch ("Worksuite-Saas"), not a brand. */
    public string $appName = 'databackd';

    public function __construct(public FinAlertEvent $event, public string $ruleName, public string $companyName, ?Company $company = null, ?string $locale = null)
    {
        if ($locale) {
            $this->locale($locale);
        }

        $this->checkedAt = now()->timezone($company?->timezone ?: config('app.timezone'))->format('d.m.Y H:i');

        $context = (array)$event->context;
        $currency = $context['currency'] ?? null;
        $fmt = $context['fmt'] ?? 'ratio';

        $this->messageText = AlertText::eventMessage($event, $locale);
        $this->severityLabel = (string)__('databackd-gaps-alerts.severities.' . $event->severity, [], $locale);
        $this->periodText = AlertText::period($event->period_from, $event->period_to, $locale);

        if ($event->value !== null) {
            $this->valueText = AlertText::format($fmt, (float)$event->value, $currency, $locale);
        }

        if ($event->threshold !== null) {
            $this->thresholdText = AlertText::format($fmt === 'pct' ? 'percent' : $fmt, (float)$event->threshold, $currency, $locale);
        }

        foreach ((array)($context['details']['items'] ?? []) as $key => $item) {
            $this->lines[] = __('databackd-gaps-alerts.mail.budget_line', [
                'subject' => __('databackd-gaps-alerts.event.subject_names.' . $key, [], $locale),
                'actual' => AlertText::format('money', (float)$item['actual'], $currency, $locale),
                'planned' => AlertText::format('money', (float)$item['budget'], $currency, $locale),
                'pct' => AlertText::format('pct', (float)$item['pct'], null, $locale),
            ], $locale);
        }

        try {
            $this->appName = trim((string)(global_setting()->global_app_name ?? '')) ?: 'databackd';
        } catch (\Throwable $e) {
            // no settings row (fresh install): keep the default
        }

        if (Route::has('databackd.gaps.alerts.history') && config('app.url')) {
            $this->openUrl = getDomainSpecificUrl(route('databackd.gaps.alerts.history', ['event' => $event->id]), $company);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('databackd-gaps-alerts.mail.subject', [
                'severity' => $this->severityLabel, 'rule' => $this->ruleName, 'company' => $this->companyName,
            ], $this->locale)
        );
    }

    public function content(): Content
    {
        return new Content(view: 'databackd.gaps.alerts.mail');
    }

}
