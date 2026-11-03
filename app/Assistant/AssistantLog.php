<?php

namespace App\Assistant;

use App\Audit\SecurityLog;
use App\Models\Announcement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * The assistant in Auditoría (log `security`, «Seguridad»): one line per
 * question with the tools it used, the model that answered, the tokens,
 * and whether it refused, fell back or failed; and one per draft the
 * person published. NEVER the question, the answer, the tool results nor
 * the draft's text (Ley 1581: only what is needed to account for it).
 *
 * The lines of today are also the daily count of {@see questionsToday()}.
 */
final class AssistantLog
{
    public const ASKED = 'assistant.asked';

    public const DRAFT_PUBLISHED = 'assistant.draft_published';

    public static function asked(User $user, Answer $answer): void
    {
        activity(SecurityLog::LOG)
            ->event(self::ASKED)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'model' => $answer->model,
                'tools' => $answer->tools,
                'tool_calls' => $answer->toolCalls,
                'input_tokens' => $answer->inputTokens,
                'output_tokens' => $answer->outputTokens,
                'fallback' => $answer->fallback,
                'refused' => $answer->refused,
                'draft' => $answer->draft !== null,
                'error' => $answer->error,
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

    /**
     * Questions this account asked since midnight in Bogotá.
     */
    public static function questionsToday(User $user): int
    {
        return Activity::query()
            ->where('log_name', SecurityLog::LOG)
            ->where('event', self::ASKED)
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey())
            ->where('created_at', '>=', CarbonImmutable::now(config('app.timezone'))->startOfDay())
            ->count();
    }
}
