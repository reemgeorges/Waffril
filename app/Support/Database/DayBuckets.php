<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * WAFFRLI (2026-11-16) — per-day dashboard aggregates computed by the
 * database instead of loading every row into PHP.
 *
 * Days are local days in a given timezone (the platform timezone). Their
 * UTC boundaries are computed in PHP (so DST and non-UTC zones are exact)
 * and passed as bindings to a portable CASE expression that turns a
 * timestamp column into a day index (0 = oldest). Works the same on MySQL 8
 * and SQLite; timestamps are stored in UTC (config app.timezone).
 */
final class DayBuckets
{
    /** @var list<string> local dates, oldest first */
    public readonly array $dates;

    /** @var list<array{0: string, 1: string}> UTC [start, end) per day */
    private array $bounds = [];

    public function __construct(int $days, string $timezone, ?Carbon $now = null)
    {
        $start = ($now ?? now())->copy()->setTimezone($timezone)->startOfDay()->subDays($days - 1);
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $dates[] = $day->toDateString();
            $this->bounds[] = [
                $day->copy()->utc()->format('Y-m-d H:i:s'),
                $day->copy()->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s'),
            ];
        }
        $this->dates = $dates;
    }

    /** UTC start of the oldest day (for an index-friendly WHERE). */
    public function from(): string
    {
        return $this->bounds[0][0];
    }

    /** UTC end of the newest day (exclusive). */
    public function until(): string
    {
        return $this->bounds[count($this->bounds) - 1][1];
    }

    /**
     * Rows per day. $query is cloned, restricted to the window on $column
     * and grouped by day index.
     *
     * @return list<int> one count per day, oldest first
     */
    public function counts(Builder $query, string $column): array
    {
        return $this->fill($this->grouped($query, $column, 'COUNT(*)'), fn ($v) => (int) $v, 0);
    }

    /**
     * Rows per day and per value of $groupColumn, in one query.
     *
     * @return array<string, list<int>> group value => one count per day, oldest first
     */
    public function countsBy(Builder $query, string $column, string $groupColumn): array
    {
        [$case, $bindings] = $this->caseExpression($query, $column);
        $rows = $this->window($query, $column)
            ->selectRaw("{$case} AS day_index, {$query->getQuery()->getGrammar()->wrap($groupColumn)} AS group_value, COUNT(*) AS aggregate", $bindings)
            ->groupBy('day_index', 'group_value')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->group_value] ??= array_fill(0, count($this->dates), 0);
            $out[(string) $row->group_value][(int) $row->day_index] = (int) $row->aggregate;
        }

        return $out;
    }

    /**
     * Average minutes between two timestamp columns, per day of $column.
     *
     * @return list<?int> null for days without rows
     */
    public function averageMinutes(Builder $query, string $fromColumn, string $toColumn): array
    {
        $grammar = $query->getQuery()->getGrammar();
        $from = $grammar->wrap($fromColumn);
        $to = $grammar->wrap($toColumn);
        $seconds = match ($query->getConnection()->getDriverName()) {
            'sqlite' => "((julianday({$to}) - julianday({$from})) * 86400.0)",
            'pgsql' => "EXTRACT(EPOCH FROM ({$to} - {$from}))",
            default => "TIMESTAMPDIFF(SECOND, {$from}, {$to})",
        };

        // Fractional minutes per row, like Carbon 3's diffInMinutes(), then rounded.
        return $this->fill(
            $this->grouped($query, $toColumn, "AVG({$seconds} / 60.0)"),
            fn ($v) => $v === null ? null : (int) round((float) $v),
            null,
        );
    }

    /** @return array<int, mixed> day index => aggregate */
    private function grouped(Builder $query, string $column, string $aggregate): array
    {
        [$case, $bindings] = $this->caseExpression($query, $column);

        return $this->window($query, $column)
            ->selectRaw("{$case} AS day_index, {$aggregate} AS aggregate", $bindings)
            ->groupBy('day_index')
            ->pluck('aggregate', 'day_index')
            ->all();
    }

    /** @return array{0: string, 1: list<string>} CASE expression giving the day index, and its bindings */
    private function caseExpression(Builder $query, string $column): array
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        $case = 'CASE';
        $bindings = [];
        foreach ($this->bounds as $i => [$start, $end]) {
            $case .= " WHEN {$wrapped} >= ? AND {$wrapped} < ? THEN {$i}";
            array_push($bindings, $start, $end);
        }

        return [$case.' END', $bindings];
    }

    /** A base query (scopes applied) restricted to the window on $column. */
    private function window(Builder $query, string $column): \Illuminate\Database\Query\Builder
    {
        return (clone $query)->toBase()
            ->where($column, '>=', $this->from())
            ->where($column, '<', $this->until());
    }

    private function fill(array $byIndex, callable $cast, mixed $empty): array
    {
        $out = [];
        foreach (array_keys($this->dates) as $i) {
            $out[] = array_key_exists($i, $byIndex) ? $cast($byIndex[$i]) : $empty;
        }

        return $out;
    }
}
