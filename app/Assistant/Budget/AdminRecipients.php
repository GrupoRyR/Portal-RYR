<?php

namespace App\Assistant\Budget;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Who gets the assistant's alerts (budget, provider failover): the super
 * admins, plus the configured super admin emails that are not users, as
 * App\Notifications\CompanyAdminNotifier builds its list.
 */
final class AdminRecipients
{
    /**
     * @return Collection<int, mixed>
     */
    public static function superAdmins(): Collection
    {
        $users = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->whereNotNull('email')
            ->get();

        $known = $users->map(fn (User $user) => strtolower((string) $user->email));

        $extra = collect(config('portal.super_admin_emails', []))
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter(fn (string $email) => $email !== '' && ! $known->contains($email))
            ->unique()
            ->map(fn (string $email) => Notification::route('mail', $email));

        return $users->concat($extra)->values();
    }
}
