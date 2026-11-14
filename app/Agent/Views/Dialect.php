<?php

namespace App\Agent\Views;

use InvalidArgumentException;

/**
 * The few SQL pieces that are not portable between MariaDB/MySQL and SQLite
 * (the suite runs on SQLite), so the agent views are written once in portable
 * SQL and only these helpers differ.
 *
 * The database keeps LOCAL times (America/Bogota, UTC-5, no DST) and the agent
 * connects with its own session, so «now» never comes from the session time
 * zone: it is UTC minus {@see AgentViews::UTC_OFFSET_HOURS}. A test may fix it
 * to a literal local moment instead.
 *
 * Dates are `YYYY-MM-DD` and datetimes `YYYY-MM-DD HH:MM:SS` in both dialects.
 */
final class Dialect
{
    public const SQLITE = 'sqlite';

    public const MYSQL = 'mysql';

    private function __construct(
        public readonly string $name,
        private readonly ?string $fixedLocalNow = null,
    ) {}

    /**
     * @param  string  $name  'sqlite', 'mysql' or 'mariadb' (a Laravel driver name)
     * @param  string|null  $fixedLocalNow  A local `Y-m-d H:i:s` moment used instead of the clock (tests)
     */
    public static function named(string $name, ?string $fixedLocalNow = null): self
    {
        $dialect = match ($name) {
            'sqlite' => self::SQLITE,
            'mysql', 'mariadb' => self::MYSQL,
            default => throw new InvalidArgumentException("Unsupported SQL dialect [{$name}]."),
        };

        if ($fixedLocalNow !== null && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $fixedLocalNow) !== 1) {
            throw new InvalidArgumentException('The fixed local moment must be «Y-m-d H:i:s».');
        }

        return new self($dialect, $fixedLocalNow);
    }

    public function isSqlite(): bool
    {
        return $this->name === self::SQLITE;
    }

    /** The local moment, as a datetime. */
    public function now(): string
    {
        $offset = abs(AgentViews::UTC_OFFSET_HOURS);

        if ($this->fixedLocalNow !== null) {
            return $this->isSqlite() ? "datetime('{$this->fixedLocalNow}')" : "TIMESTAMP('{$this->fixedLocalNow}')";
        }

        return $this->isSqlite()
            ? "datetime('now', '-{$offset} hours')"
            : "(UTC_TIMESTAMP() - INTERVAL {$offset} HOUR)";
    }

    /** Midnight of the local day, as a datetime. */
    public function startOfToday(): string
    {
        return $this->isSqlite() ? 'datetime(date('.$this->now().'))' : 'CAST(DATE('.$this->now().') AS DATETIME)';
    }

    /** The local day, as a date. */
    public function today(): string
    {
        return $this->dateOf($this->now());
    }

    public function dateOf(string $datetime): string
    {
        return $this->isSqlite() ? "date({$datetime})" : "DATE({$datetime})";
    }

    /** $hours may be negative or an expression. */
    public function addHours(string $datetime, string $hours): string
    {
        return $this->isSqlite()
            ? "datetime({$datetime}, ({$hours}) || ' hours')"
            : "({$datetime} + INTERVAL ({$hours}) HOUR)";
    }

    /** $days is a whole number (an expression), not negative. */
    public function addDays(string $date, string $days): string
    {
        return $this->isSqlite()
            ? "date({$date}, '+' || ({$days}) || ' days')"
            : "DATE_ADD({$date}, INTERVAL ({$days}) DAY)";
    }

    /** Whole days from $earlier to $later (dates). */
    public function dayDiff(string $later, string $earlier): string
    {
        return $this->isSqlite()
            ? "CAST(ROUND(julianday({$later}) - julianday({$earlier})) AS INTEGER)"
            : "DATEDIFF({$later}, {$earlier})";
    }

    public function year(string $date): string
    {
        return $this->isSqlite() ? "CAST(strftime('%Y', {$date}) AS INTEGER)" : "YEAR({$date})";
    }

    public function month(string $date): string
    {
        return $this->isSqlite() ? "CAST(strftime('%m', {$date}) AS INTEGER)" : "MONTH({$date})";
    }

    public function dayOfMonth(string $date): string
    {
        return $this->isSqlite() ? "CAST(strftime('%d', {$date}) AS INTEGER)" : "DAYOFMONTH({$date})";
    }

    public function makeDate(string $year, string $month, string $day): string
    {
        return $this->isSqlite()
            ? "date(printf('%04d-%02d-%02d', {$year}, {$month}, {$day}))"
            : "STR_TO_DATE(CONCAT({$year}, '-', {$month}, '-', {$day}), '%Y-%m-%d')";
    }

    public function castInt(string $expression): string
    {
        return $this->isSqlite() ? "CAST({$expression} AS INTEGER)" : "CAST({$expression} AS SIGNED)";
    }

    /** Whether the JSON array in $column holds the string $value. */
    public function jsonArrayContains(string $column, string $value): string
    {
        return $this->isSqlite()
            ? "EXISTS (SELECT 1 FROM json_each({$column}) WHERE json_each.value = '{$value}')"
            : "JSON_CONTAINS({$column}, '\"{$value}\"')";
    }
}
