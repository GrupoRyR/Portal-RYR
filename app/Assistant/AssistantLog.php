<?php

namespace App\Assistant;

use App\Audit\SecurityLog;
use App\Models\Announcement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * The assistant in Auditoría (log `security`, «Seguridad»): one line per
 * question with the model, the feature (`chat` or `draft`), the tools it
 * used, its tokens (uncached input, output, cache writes, cache reads),
 * its cost in US dollars, and whether it refused, fell back, was cut or
 * failed; one per draft the person published; one per question the
 * monthly budget stopped; one per budget alert. NEVER the question, the
 * answer, the tool results nor the draft's text (Ley 1581: only what is
 * needed to account for it).
 *
 * The question lines are also the daily cap ({@see questionsToday()}), the
 * month's spend (App\Assistant\Budget\AssistantBudget) and the usage report
 * (`portal:assistant-usage`).
 */
final class AssistantLog
{
    public const ASKED = 'assistant.asked';

    public const DRAFT_PUBLISHED = 'assistant.draft_published';

    public const BUDGET_BLOCKED = 'assistant.budget_blocked';

    public const BUDGET_ALERT = 'assistant.budget_alert';

    public const FAILOVER = 'assistant.failover';

    public const PRIMARY_DOWN_ALERT = 'assistant.primary_down_alert';

    public static function asked(User $user, Answer $answer): void
    {
        activity(SecurityLog::LOG)
            ->event(self::ASKED)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'provider' => $answer->provider,
                'model' => $answer->model,
                'feature' => $answer->feature(),
                'tools' => $answer->tools,
                'tool_calls' => $answer->toolCalls,
                'model_calls' => $answer->usage->calls,
                'input_tokens' => $answer->usage->inputTokens,
                'output_tokens' => $answer->usage->outputTokens,
                'cache_creation_input_tokens' => $answer->usage->cacheCreationInputTokens,
                'cache_read_input_tokens' => $answer->usage->cacheReadInputTokens,
                'cost_usd' => round($answer->usage->costUsd, 6),
                'fallback' => $answer->fallback,
                'failover' => $answer->failover,
                'refused' => $answer->refused,
                'truncated' => $answer->truncated,
                'draft' => $answer->draft !== null,
                'error' => $answer->error,
                'stopped' => $answer->stopped,
            ])
            ->log(self::ASKED);
    }

    public static function draftPublished(User $user, Announcement $announcement): void
    {
        activity(SecurityLog::LOG)
            ->event(self::DRAFT_PUBLISHED)
            ->causedBy($user)
            ->performedOn($announcement)
            ->withProperties(['announcement_id' => $announcement->getKey()])
            ->log(self::DRAFT_PUBLISHED);
    }

    public static function budgetBlocked(User $user, float $spent, float $budget): void
    {
        activity(SecurityLog::LOG)
            ->event(self::BUDGET_BLOCKED)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['spent_usd' => round($spent, 2), 'budget_usd' => round($budget, 2)])
            ->log(self::BUDGET_BLOCKED);
    }

    /**
     * @param  int  $threshold  80 or 100 (percent of the budget).
     */
    public static function budgetAlert(int $threshold, string $month, float $spent, float $budget, int $recipients): void
    {
        activity(SecurityLog::LOG)
            ->event(self::BUDGET_ALERT)
            ->causedByAnonymous()
            ->withProperties([
                'threshold' => $threshold,
                'month' => $month,
                'spent_usd' => round($spent, 2),
                'budget_usd' => round($budget, 2),
                'recipients' => $recipients,
            ])
            ->log(self::BUDGET_ALERT);
    }

    /**
     * A conversation moved from one provider to another: from, to and the
     * error type, never its message.
     */
    public static function failover(User $user, string $from, string $to, string $reason): void
    {
        activity(SecurityLog::LOG)
            ->event(self::FAILOVER)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['from' => $from, 'to' => $to, 'reason' => $reason])
            ->log(self::FAILOVER);
    }

    public static function primaryDownAlert(string $provider, string $reason, int $recipients): void
    {
        activity(SecurityLog::LOG)
            ->event(self::PRIMARY_DOWN_ALERT)
            ->causedByAnonymous()
            ->withProperties(['provider' => $provider, 'reason' => $reason, 'recipients' => $recipients])
            ->log(self::PRIMARY_DOWN_ALERT);
    }

    /**
     * Questions this account asked since midnight in Bogotá.
     */
    public static function questionsToday(User $user): int
    {
        return self::questions(CarbonImmutable::now(config('app.timezone'))->startOfDay())
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey())
            ->count();
    }

    /**
     * The question lines since $from (app timezone, Bogotá).
     *
     * @return Builder<Activity>
     */
    public static function questions(CarbonImmutable $from): Builder
    {
        return Activity::query()
            ->where('log_name', SecurityLog::LOG)
            ->where('event', self::ASKED)
            ->where('created_at', '>=', $from->setTimezone(config('app.timezone')));
    }
}
