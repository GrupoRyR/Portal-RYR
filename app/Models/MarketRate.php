<?php

namespace App\Models;

use Database\Factories\MarketRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
            'valid_from' => 'date',
            'valid_until' => 'date',
            'fetched_at' => 'datetime',
        ];
    }
}
