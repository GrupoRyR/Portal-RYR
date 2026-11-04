<?php

namespace App\View\Assistant;

use App\Admin\RoleMatrix;
use App\Assistant\Tools\AnnouncementDraft;
use App\Models\Company;
use App\Models\User;
use App\View\Home\Greeting;
use Illuminate\Support\Str;

/**
 * The assistant's conversation as JSON, for the floating chat panel
 * (AssistantController with `Accept: application/json`). Every `html`
 * is rendered exactly as /asistente renders it: the question escaped,
 * the answer through AnswerText. Everything else (draft title, excerpt,
 * greeting) is plain text the panel must insert as text, never as HTML.
 * A draft is only announced here: it is reviewed and published on
 * /asistente (AssistantDraftController), never from the panel.
 */
final class ConversationJson
{
    /** Characters of the draft's body the panel shows. */
    public const EXCERPT_LENGTH = 140;

    /**
     * @param  list<array{question: string, answer: string}>  $turns
     * @return array{turns: list<array{role: string, html: string}>, draft: array{title: string, excerpt: string, review_url: string}|null, remaining: int, error: null}
     */
    public static function payload(array $turns, ?AnnouncementDraft $draft, int $remaining): array
    {
        return [
            'turns' => self::turns($turns),
            'draft' => $draft === null ? null : self::draft($draft),
            'remaining' => $remaining,
            'error' => null,
        ];
    }

    /**
     * @param  list<array{question: string, answer: string}>  $turns
     * @return list<array{role: string, html: string}>
     */
    public static function turns(array $turns): array
    {
        $messages = [];

        foreach ($turns as $turn) {
            $messages[] = ['role' => 'user', 'html' => e($turn['question'])];
            $messages[] = ['role' => 'assistant', 'html' => AnswerText::html($turn['answer'])->toHtml()];
        }

        return $messages;
    }

    /**
     * @return array{title: string, excerpt: string, review_url: string}
     */
    public static function draft(AnnouncementDraft $draft): array
    {
        return [
            'title' => $draft->title,
            'excerpt' => Str::limit($draft->body, self::EXCERPT_LENGTH, '…'),
            'review_url' => route('assistant.show', absolute: false).'#borrador',
        ];
    }

    /**
     * What the panel greets with: the first name, the active company (as
     * /asistente names it) and the highest role's label, if any.
     *
     * @return array{first_name: string, company: string, role: string|null}
     */
    public static function greeting(User $user): array
    {
        $role = null;

        foreach (RoleMatrix::ROLES as $name => $label) {
            if ($user->hasRole($name)) {
                $role = $label;
                break;
            }
        }

        return [
            'first_name' => Greeting::firstName($user->name),
            'company' => $user->company?->short_name ?: Company::displayName($user->company),
            'role' => $role,
        ];
    }
}
