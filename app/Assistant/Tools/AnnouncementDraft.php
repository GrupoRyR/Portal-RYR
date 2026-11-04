<?php

namespace App\Assistant\Tools;

/**
 * An announcement the model proposed (`borrador_de_anuncio`). Only shown
 * on /asistente, prefilled in a form: nothing is written until the person
 * sends that form themselves (App\Http\Controllers\Assistant\AssistantDraftController).
 */
final readonly class AnnouncementDraft
{
    public function __construct(
        public string $title,
        public string $body,
        public bool $requiresRead,
    ) {}

    /**
     * @return array{title: string, body: string, requires_read: bool}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'requires_read' => $this->requiresRead];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        if (! is_string($stored['title'] ?? null) || ! is_string($stored['body'] ?? null)) {
            return null;
        }

        return new self($stored['title'], $stored['body'], ($stored['requires_read'] ?? false) === true);
    }
}
