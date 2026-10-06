<?php

namespace App\Microsoft;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Microsoft 365 group the user belonged to at their last sign-in.
 */
#[Fillable(['group_id', 'display_name'])]
class UserGroup extends Model
{
    protected $table = 'user_groups';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Group IDs are GUIDs; Microsoft may send them in any case.
     */
    protected function groupId(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower(trim($value)),
        );
    }
}
