<?php

namespace App\Models;

use App\Support\DeviceClassifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opening of an app: who, which app, under which company, when and
 * from which kind of device. Rows are append-only.
 */
#[Fillable(['user_id', 'app_id', 'company_id', 'device', 'launched_at'])]
class AppLaunch extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['launched_at' => 'datetime'];
    }

    /**
     * Records that the user opened the app now.
     */
    public static function record(User $user, App $app, ?string $userAgent): self
    {
        return self::create([
            'user_id' => $user->id,
            'app_id' => $app->id,
            'company_id' => $user->company_id,
            'device' => DeviceClassifier::classify($userAgent),
            'launched_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * The company the user belonged to when opening the app.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
