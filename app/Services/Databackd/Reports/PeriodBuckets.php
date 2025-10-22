<?php

namespace App\Services\Databackd\Reports;

use Carbon\Carbon;

/**
 * Splits a date range into day / week / month / quarter / year buckets and produces the matching MySQL
 * GROUP BY expression, so PHP buckets and SQL keys always agree.
 *
 * Bucket keys: day '2026-10-27', week '2026-W43' (ISO), month '2026-10', quarter '2026-Q4', year '2026'.
 */
class PeriodBuckets
{

    const PERIODICITIES = ['day', 'week', 'month', 'quarter', 'year'];

    /** Hard limit of buckets (a daily report for more than ~13 months is cut short of readable). */
    const MAX_BUCKETS = 800;

    /**
     * Buckets covering [$from, $to]; the first and the last bucket are clipped to the range, so the sum over
     * all buckets equals the sum over the whole range.
     *
     * @return array<int, array{key: string, label: string, from: Carbon, to: Carbon}>
     */
    public static function make(Carbon $from, Carbon $to, string $periodicity): array
    {
        if (!in_array($periodicity, self::PERIODICITIES, true)) {
            $periodicity = 'month';
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $buckets = [];

        $cursor = self::startOf($from, $periodicity);

        while ($cursor->lte($to) && count($buckets) < self::MAX_BUCKETS) {
            $end = self::endOf($cursor, $periodicity);
            $buckets[] = [
                'key' => self::key($cursor, $periodicity),
                'label' => self::label($cursor, $periodicity),
                'from' => $cursor->lt($from) ? $from->copy() : $cursor->copy(),
                'to' => $end->gt($to) ? $to->copy() : $end->copy(),
            ];
            $cursor = $end->copy()->addDay()->startOfDay();
        }

        return $buckets;
    }

    /**
     * MySQL expression producing the bucket key for a date column, e.g. sqlExpression('month', 'fin_entries.entry_date').
     */
    public static function sqlExpression(string $periodicity, string $column): string
    {
        // S6/P3: the result is concatenated into selectRaw()/groupByRaw(). $periodicity is whitelisted (an unknown value is
        // "month", like make()) and $column must be a plain [table.]column identifier - callers pass literals, this keeps it so.
        if (!in_array($periodicity, self::PERIODICITIES, true)) {
            $periodicity = 'month';
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            throw new \InvalidArgumentException('Invalid column name for a period expression.');
        }

        switch ($periodicity) {
            case 'day':
                return "DATE_FORMAT($column, '%Y-%m-%d')";
            case 'week':
                return "CONCAT(LEFT(YEARWEEK($column, 3), 4), '-W', RIGHT(YEARWEEK($column, 3), 2))";
            case 'quarter':
                return "CONCAT(YEAR($column), '-Q', QUARTER($column))";
            case 'year':
                return "DATE_FORMAT($column, '%Y')";
            case 'month':
            default:
                return "DATE_FORMAT($column, '%Y-%m')";
        }
    }

    /** Bucket key of a single date (same format as the SQL expression). */
    public static function key(Carbon $date, string $periodicity): string
    {
        switch ($periodicity) {
            case 'day':
                return $date->format('Y-m-d');
            case 'week':
                return $date->format('o-\WW');
            case 'quarter':
                return $date->format('Y') . '-Q' . $date->quarter;
            case 'year':
                return $date->format('Y');
            case 'month':
            default:
                return $date->format('Y-m');
        }
    }

    /** Short Bulgarian/English label (lang databackd-reports-common). */
    public static function label(Carbon $date, string $periodicity): string
    {
        switch ($periodicity) {
            case 'day':
                return $date->format('d.m.Y');
            case 'week':
                return __('databackd-reports-common.week_short') . ' ' . $date->format('W') . ' · ' . $date->format('o');
            case 'quarter':
                return __('databackd-reports-common.quarter_short') . $date->quarter . ' ' . $date->format('Y');
            case 'year':
                return $date->format('Y');
            case 'month':
            default:
                return __('databackd-reports-common.months_short.' . (int)$date->format('n')) . ' ' . $date->format('Y');
        }
    }

    protected static function startOf(Carbon $date, string $periodicity): Carbon
    {
        switch ($periodicity) {
            case 'day':
                return $date->copy()->startOfDay();
            case 'week':
                return $date->copy()->startOfWeek(Carbon::MONDAY);
            case 'quarter':
                return $date->copy()->startOfQuarter();
            case 'year':
                return $date->copy()->startOfYear();
            case 'month':
            default:
                return $date->copy()->startOfMonth();
        }
    }

    protected static function endOf(Carbon $date, string $periodicity): Carbon
    {
        switch ($periodicity) {
            case 'day':
                return $date->copy()->endOfDay();
            case 'week':
                return $date->copy()->endOfWeek(Carbon::SUNDAY);
            case 'quarter':
                return $date->copy()->endOfQuarter();
            case 'year':
                return $date->copy()->endOfYear();
            case 'month':
            default:
                return $date->copy()->endOfMonth();
        }
    }

}
