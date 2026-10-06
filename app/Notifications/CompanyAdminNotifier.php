<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells every portal administrator (super_admin, portal_admin and the
 * configured super admin emails) about a company access change.
 *
 * Sent after the surrounding transaction commits, synchronously. A mail
 * failure never breaks the action that triggered it: it is logged instead.
 */
class CompanyAdminNotifier
{
    public function notify(Company $company, string $event, ?User $actor): void
    {
        DB::afterCommit(function () use ($company, $event, $actor) {
            $notice = new CompanyChangedNotice($company, $event, $actor?->name);

            foreach ($this->recipients() as $recipient) {
                try {
                    Notification::sendNow($recipient, $notice);
                } catch (Throwable $e) {
                    Log::warning('Company notice could not be sent.', [
                        'company_id' => $company->id,
                        'event' => $event,
                        'exception' => $e::class,
                    ]);
                }
            }
        });
    }

    /**
     * Users with an admin role, plus configured emails that are not users.
     *
     * @return Collection<int, mixed>
     */
    private function recipients(): Collection
    {
        $users = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super_admin', 'portal_admin']))
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
