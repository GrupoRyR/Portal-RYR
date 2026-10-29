<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person with an account in several companies of the group, at most
 * one per company. Only App\Auth\LinkedAccounts links or unlinks them.
 *
 * A person never authorizes anything: roles, groups, favorites,
 * announcements and app access stay per account.
 */
class Person extends Model
{
    protected $table = 'people';

    /**
     * @return HasMany<User, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
