<?php

namespace App\Models;

use Database\Factories\MarketRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One market figure in Colombian pesos for the home band: the TRM, or the
 * euro (TRM × the ECB's dollars per euro). Written by
 * App\Markets\MarketSync, read through App\Markets\MarketBoard. Unique per
 * code and start date.
 *
 * @property int $id
 * @property string $code
 * @property string $value Pesos, four decimals.
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property string $source
 * @property Carbon $fetched_at
 */
#[Fillable(['code', 'value', 'valid_from', 'valid_until', 'source', 'fetched_at'])]
class MarketRate extends Model
{
    /** @use HasFactory<MarketRateFactory> */
    use HasFactory;

    public const TRM = 'trm';

    public const EUR = 'eur';

    /** In the order the band shows them. */
    public const CODES = [self::TRM, self::EUR];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            // The format only shapes serialization (toArray, JSON); what is
            // written is decided by the mutators below.
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Written as `Y-m-d` whatever is assigned (a Carbon, «2026-11-03 00:00:00»):
     * a date column compares as text in SQLite, where a time breaks `<=`.
     */
    protected function validFrom(): Attribute
    {
        return Attribute::set(fn (mixed $value) => self::day($value));
    }

    protected function validUntil(): Attribute
    {
        return Attribute::set(fn (mixed $value) => self::day($value));
    }

    private static function day(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
