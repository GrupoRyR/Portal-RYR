<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A status change reported by an external system through the API
 * (App\Api\StatusReporter). Repeated identical reports add no row.
 */
#[Fillable(['app_id', 'integration_id', 'status', 'message', 'source', 'reference'])]
class AppStatusEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
