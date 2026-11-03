<?php

namespace App\Models;

use Database\Factories\CompanyKpiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One figure of a company's management panel (odd/tasks/panel-gerencial.md),
 * e.g. «Ventas del mes» against its goal. A company has one current set of
 * at most MAX_ITEMS figures, replaced whole by `PUT /api/v1/kpis`
 * (App\Management\KpiPublisher); the set's period, date and source repeat
 * on each row. Read it through App\Management\ManagementPanel.
 *
 * The values are business figures: they are never logged nor written to
 * the activity log.
 */
#[Fillable([
    'company_id',
    'integration_id',
    'position',
    'key',
    'label',
    'value',
    'unit',
    'goal',
    'note',
    'period_label',
    'as_of',
    'source_label',
])]
class CompanyKpi extends Model
{
    /** @use HasFactory<CompanyKpiFactory> */
    use HasFactory;

    public const MAX_ITEMS = 8;

    public const MAX_KEY = 40;

    public const MAX_LABEL = 60;

    public const MAX_UNIT = 16;

    public const MAX_NOTE = 120;

    public const MAX_PERIOD_LABEL = 40;

    public const MAX_SOURCE_LABEL = 80;

    /** Largest absolute value or goal: fits decimal(20, 4). */
    public const MAX_ABS_VALUE = 1e15;

    /**
     * A body of the PUT larger than this answers 413. The largest valid set
     * is about 13 KB when every non-ASCII character is escaped as \uXXXX.
     */
    public const MAX_BODY_BYTES = 16384;

    /** The earliest accepted `as_of`. */
    public const EARLIEST_AS_OF = '2000-01-01T00:00:00Z';

    /** How far ahead of now an `as_of` may be (clock skew, time zones). */
    public const MAX_AS_OF_AHEAD_HOURS = 24;

    /** Lowercase slug: letters and digits, single `-` or `_` between them. */
    public const KEY_PATTERN = '/\A[a-z0-9]+(?:[-_][a-z0-9]+)*\z/';

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'value' => 'float',
            'goal' => 'float',
            'as_of' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
