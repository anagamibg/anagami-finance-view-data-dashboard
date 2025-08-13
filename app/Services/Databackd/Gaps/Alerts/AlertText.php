<?php

namespace App\Services\Databackd\Gaps\Alerts;

use App\Models\Company;
use App\Models\Databackd\FinAlertEvent;
use App\Models\Databackd\FinAlertRule;
use App\Models\Databackd\FinBudget;
use App\Models\Databackd\FinRatio;
use App\Services\Databackd\Analytics\RatioCalculator;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Texts and number formats of the alerts (rule descriptions, alert messages, period labels).
 *
 * The message of an alert is stored twice: as a ready string in fin_alert_events.message (company locale, a
 * fallback for events written by other code) and as a template in fin_alert_events.context
 * ({message_key, message_params, message_fmt, currency, entity}), so the history can show it in the locale of the
 * viewer. All texts are in resources/lang/{eng,bg}/databackd-gaps-alerts.php.
 */
class AlertText
{

    /** @var array<int, string> company id => currency code */
    protected static array $currencies = [];

    /**
     * Number with the thousands / decimal separators of the locale (bg: "1 234,56", others: "1,234.56").
     */
    public static function number(?float $value, int $decimals = 2, ?string $locale = null): string
    {
        if ($value === null) {
            return '—';
        }

        $locale = $locale ?? app()->getLocale();

        return $locale === 'bg'
            ? number_format($value, $decimals, ',', ' ')
            : number_format($value, $decimals, '.', ',');
    }

    /**
     * Value formatted by kind: ratio | currency (ratio formats, 2 decimals), percent (12.4 %), pct (signed percent,
     * +12.4 %), days (45 days), money (1 234,56 €).
     */
    public static function format(string $fmt, ?float $value, ?string $currency = null, ?string $locale = null): string
    {
        if ($value === null) {
            return '—';
        }

        switch ($fmt) {
            case 'percent':
                return self::number($value, 1, $locale) . ' %';
            case 'pct':
                return ($value > 0 ? '+' : ($value < 0 ? '−' : '')) . self::number(abs($value), 1, $locale) . ' %';
            case 'days':
                return self::number($value, 0, $locale) . ' ' . __('databackd-gaps-alerts.units.days', [], $locale);
            case 'money':
                return self::number($value, 2, $locale) . ' ' . self::currencySymbol($currency);
            default:
                return self::number($value, 2, $locale);
        }
    }

    public static function currencySymbol(?string $code): string
    {
        $code = strtoupper((string)$code);

        return $code === '' || $code === 'EUR' ? '€' : $code;
    }

    /** Currency code of a company (EUR when unknown). */
    public static function companyCurrency(int $companyId): string
    {
        if (!isset(self::$currencies[$companyId])) {
            self::$currencies[$companyId] = Company::with('currency')->find($companyId)?->currency?->currency_code ?: 'EUR';
        }

        return self::$currencies[$companyId];
    }

    /**
     * Format of the ratio value as used in the alert texts (percent, days, ... ).
     */
    public static function ratioFormat(?FinRatio $ratio): string
    {
        return $ratio && in_array($ratio->format, ['percent', 'days'], true) ? $ratio->format : 'ratio';
    }

    /**
     * Target range of a ratio for the form hint ("1,20 – 2,50", "≥ 1,20", "≤ 45 дни"), null when it has no target.
     */
    public static function ratioTarget(FinRatio $ratio): ?string
    {
        $fmt = fn($value) => RatioCalculator::format($ratio, (float)$value);

        if ($ratio->target_min !== null && $ratio->target_max !== null) {
            return $fmt($ratio->target_min) . ' – ' . $fmt($ratio->target_max);
        }

        if ($ratio->target_min !== null) {
            return '≥ ' . $fmt($ratio->target_min);
        }

        if ($ratio->target_max !== null) {
            return '≤ ' . $fmt($ratio->target_max);
        }

        return null;
    }

    /**
     * Human readable period: "октомври 2026", "януари–септември 2026", "01.10.2026–28.10.2026" or a single date.
     */
    public static function period(?Carbon $from, ?Carbon $to, ?string $locale = null): string
    {
        if (!$from && !$to) {
            return '';
        }

        $from = ($from ?? $to)->copy()->startOfDay();
        $to = ($to ?? $from)->copy()->startOfDay();

        if ($from->isSameDay($to)) {
            return $to->format('d.m.Y');
        }

        $toIsMonthEnd = $to->isSameDay($to->copy()->endOfMonth()->startOfDay());
        $loc = self::carbonLocale($locale);

        // Bulgarian month names are written in lower case, English ones are capitalized
        $month = fn(Carbon $date) => $loc === 'bg'
            ? Str::lower($date->copy()->locale($loc)->translatedFormat('F'))
            : $date->copy()->locale($loc)->translatedFormat('F');

        if ($from->day === 1 && $toIsMonthEnd && $from->isSameMonth($to)) {
            return $month($from) . ' ' . $from->year;
        }

        if ($from->month === 1 && $from->day === 1 && $toIsMonthEnd && $from->year === $to->year) {
            return $month($from) . '–' . $month($to) . ' ' . $to->year;
        }

        return $from->format('d.m.Y') . '–' . $to->format('d.m.Y');
    }

    /**
     * The alert message rendered from the event context (null when the event has no template - e.g. written by
     * other code - use the stored message then).
     */
    public static function message(array $context, ?Carbon $from, ?Carbon $to, ?string $locale = null): ?string
    {
        $key = $context['message_key'] ?? null;

        if (!$key) {
            return null;
        }

        $currency = $context['currency'] ?? null;
        $params = (array)($context['message_params'] ?? []);

        foreach ((array)($context['message_fmt'] ?? []) as $name => $fmt) {
            if (array_key_exists($name, $params)) {
                $params[$name] = self::format($fmt, $params[$name] === null ? null : (float)$params[$name], $currency, $locale);
            }
        }

        if (isset($params['subject_key'])) {
            $params['subject'] = __('databackd-gaps-alerts.event.subjects.' . $params['subject_key'], [], $locale);
        }

        $params['period'] = self::period($from, $to, $locale);

        $text = (string)__('databackd-gaps-alerts.event.' . $key, $params, $locale);

        if (!empty($context['entity'])) {
            $text .= ' ' . __('databackd-gaps-alerts.event.entity_suffix', ['entity' => $context['entity']], $locale);
        }

        return $text;
    }

    /**
     * Message of a stored event in the viewer's locale (template from the context, else the stored text).
     */
    public static function eventMessage(FinAlertEvent $event, ?string $locale = null): string
    {
        $text = self::message((array)$event->context, $event->period_from, $event->period_to, $locale);

        return $text ?? (string)$event->message;
    }

    /**
     * Short description of the condition of a rule, e.g. "Текуща ликвидност извън 1,20 – 2,50".
     *
     * @param \Illuminate\Support\Collection|array $ratios FinRatio models keyed by id
     * @param \Illuminate\Support\Collection|array $budgets FinBudget models keyed by id
     */
    public static function condition(FinAlertRule $rule, $ratios, $budgets, ?string $currency = null): string
    {
        $p = (array)$rule->params;
        $currency = $currency ?: self::companyCurrency((int)$rule->company_id);

        switch ($rule->type) {
            case 'ratio_range':
                /** @var FinRatio|null $ratio */
                $ratio = $ratios[$p['ratio_id'] ?? 0] ?? null;
                $name = $ratio ? $ratio->name : __('databackd-gaps-alerts.condition.missing_ratio');
                $fmt = fn($v) => $ratio ? RatioCalculator::format($ratio, (float)$v) : self::number((float)$v);
                $min = $p['min'] ?? null;
                $max = $p['max'] ?? null;

                if ($min !== null && $max !== null) {
                    return __('databackd-gaps-alerts.condition.ratio_range', ['ratio' => $name, 'min' => $fmt($min), 'max' => $fmt($max)]);
                }

                if ($min !== null) {
                    return __('databackd-gaps-alerts.condition.ratio_min', ['ratio' => $name, 'min' => $fmt($min)]);
                }

                return __('databackd-gaps-alerts.condition.ratio_max', ['ratio' => $name, 'max' => $fmt($max)]);

            case 'budget_deviation':
                /** @var FinBudget|null $budget */
                $budget = $budgets[$p['budget_id'] ?? 0] ?? null;

                $metric = $p['metric'] ?? 'any';

                return __($metric === 'any' || !in_array($metric, ['revenue', 'expense', 'profit'], true)
                    ? 'databackd-gaps-alerts.condition.budget_deviation'
                    : 'databackd-gaps-alerts.condition.budget_metric', [
                    'budget' => $budget ? $budget->name : __('databackd-gaps-alerts.condition.missing_budget'),
                    'pct' => self::format('percent', (float)($p['pct'] ?? 0)),
                    'metric' => __('databackd-gaps-alerts.condition.metrics.' . $metric),
                ]);

            case 'cash_below':
                return __('databackd-gaps-alerts.condition.cash_below', ['amount' => self::format('money', (float)($p['amount'] ?? 0), $currency)]);

            case 'receivables_above':
                return __('databackd-gaps-alerts.condition.receivables_above', ['amount' => self::format('money', (float)($p['amount'] ?? 0), $currency)]);
        }

        return '';
    }

    protected static function carbonLocale(?string $locale): string
    {
        $locale = $locale ?? app()->getLocale();

        return in_array($locale, ['bg', 'de', 'ru', 'el', 'es', 'fr', 'it', 'tr', 'pl', 'ro'], true) ? $locale : 'en';
    }

}
