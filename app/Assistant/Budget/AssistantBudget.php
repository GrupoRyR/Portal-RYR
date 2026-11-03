<?php

namespace App\Assistant\Budget;

use App\Assistant\AssistantLog;
use App\Assistant\AssistantSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * The monthly spend cap of the assistant (`assistant.monthly_budget_usd`,
 * ASSISTANT_MONTHLY_BUDGET_USD): the calendar month in Bogotá, summed from
 * the `cost_usd` of this month's question lines in Auditoría.
 *
 * - At or over the cap, {@see exceeded()} is true and no question reaches
 *   the API (App\Http\Controllers\Assistant\AssistantController).
 * - At 80 % and at 100 %, the super admins get one email per threshold and
 *   month ({@see alertIfNeeded()}). Which alerts went out is recorded in
 *   `portal_settings` (key `assistant_budget_alert:YYYY-MM:80`): the insert
 *   is the lock, so two requests at once send it once. The mail goes after
 *   the commit and a failure is logged, never thrown.
 *
 * The Anthropic Console's workspace spend limit is the second barrier
 * (docs/despliegue.md §1.23).
 */
class AssistantBudget
{
    public const THRESHOLDS = [80, 100];

    public const SETTING_PREFIX = 'assistant_budget_alert:';

    public function __construct(private readonly AssistantSettings $settings) {}

    public function limit(): float
    {
        return $this->settings->monthlyBudgetUsd;
    }

    /**
     * US dollars spent since the 1st of this month, 00:00 in Bogotá.
     */
    public function spentThisMonth(): float
    {
        return (float) AssistantLog::questions(self::monthStart())
            ->get(['properties'])
            ->sum(fn (Activity $line) => (float) $line->properties->get('cost_usd', 0));
    }

    public function exceeded(): bool
    {
        return $this->spentThisMonth() >= $this->limit();
    }

    /**
     * Percent of the budget spent this month (0 with no budget set).
     */
    public function percent(?float $spent = null): float
    {
        return $this->limit() <= 0 ? 100.0 : (($spent ?? $this->spentThisMonth()) / $this->limit()) * 100;
    }

    /**
     * Sends the 80 % and 100 % alerts not sent yet this month.
     */
    public function alertIfNeeded(): void
    {
        $spent = $this->spentThisMonth();
        $percent = $this->percent($spent);
        $month = self::monthStart()->format('Y-m');

        foreach (self::THRESHOLDS as $threshold) {
            if ($percent < $threshold) {
                continue;
            }

            $claimed = DB::table('portal_settings')->insertOrIgnore([
                'key' => self::SETTING_PREFIX.$month.':'.$threshold,
                'value' => json_encode(['spent_usd' => round($spent, 2), 'sent_at' => now()->toIso8601String()]),
                'updated_at' => now(),
            ]);

            if ($claimed === 1) {
                $this->send($threshold, $month, $spent);
            }
        }
    }

    public static function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfMonth();
    }

    private function send(int $threshold, string $month, float $spent): void
    {
        $budget = $this->limit();
        $recipients = AdminRecipients::superAdmins();

        AssistantLog::budgetAlert($threshold, $month, $spent, $budget, $recipients->count());

        DB::afterCommit(function () use ($threshold, $spent, $budget, $recipients) {
            $notice = new BudgetAlertNotice($threshold, $spent, $budget);

            foreach ($recipients as $recipient) {
                try {
                    Notification::sendNow($recipient, $notice);
                } catch (Throwable $e) {
                    Log::warning('Assistant budget alert could not be sent.', ['threshold' => $threshold, 'exception' => $e::class]);
                }
            }
        });
    }
}
