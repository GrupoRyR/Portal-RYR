<?php

namespace App\Models;

use App\Markets\MarketUnit;
use Database\Factories\MarketInputFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A price of one company typed by hand in Administración › Mercados
 * (odd/tasks/mercados-o5.md M4), usually a supplier quote of an input no
 * free source publishes. The home band of the company shows the latest
 * price of each name, with its change against the previous price of that
 * name (App\Markets\MarketBoard). Every change goes to Auditoría.
 *
 * @property int $id
 * @property int $company_id
 * @property string $label
 * @property MarketUnit $unit One of MarketUnit::manualValues().
 * @property string $value Four decimals.
 * @property Carbon $valid_on
 * @property string|null $source
 * @property int|null $created_by
 */
#[Fillable(['company_id', 'label', 'unit', 'value', 'valid_on', 'source', 'created_by'])]
class MarketInput extends Model
{
    /** @use HasFactory<MarketInputFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for manual prices (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'market_inputs';

    public const LABEL_MAX = 40;

    public const SOURCE_MAX = 80;

    /** The largest value `decimal(14,4)` holds. */
    public const VALUE_MAX = '9999999999.9999';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::AUDIT_LOG)
            ->logOnly(['label', 'unit', 'value', 'valid_on', 'source', 'company_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => MarketUnit::class,
            'value' => 'decimal:4',
            'valid_on' => 'date:Y-m-d',
        ];
    }

    /**
     * Written as `Y-m-d` whatever is assigned: a date column compares as
     * text in SQLite, where a time breaks `<=` (as in App\Models\MarketRate).
     */
    protected function validOn(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $value === null ? null : Carbon::parse($value)->toDateString());
    }

    /**
     * «$4.200,00/kg» or «$0,1234/kg»: the value in its unit with the
     * decimals it was typed with, as the band prints it.
     */
    public function displayValue(): string
    {
        return $this->unit->format((float) $this->value, precise: true).$this->unit->suffix();
    }

    /**
     * One name without case or accents («soda caustica» for «Soda
     * Cáustica»), as MariaDB's utf8mb4_unicode_ci compares it. The admin
     * screen reuses a spelling by it and the band groups names by it, so
     * SQLite and production agree.
     */
    public static function fold(string $label): string
    {
        return mb_strtolower(Str::ascii($label));
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The newest quote first; two of one day, the last one entered first.
     *
     * @param  Builder<MarketInput>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('valid_on')->orderByDesc('id');
    }
}
