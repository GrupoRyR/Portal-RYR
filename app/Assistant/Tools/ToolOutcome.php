<?php

namespace App\Assistant\Tools;

/**
 * What one tool call gives back: the content for the model (JSON, or an
 * error text in Spanish) and, for `borrador_de_anuncio`, the draft the page
 * shows. Never logged.
 */
final readonly class ToolOutcome
{
    private function __construct(
        public string $content,
        public bool $isError,
        public ?AnnouncementDraft $draft = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function data(array $data, ?AnnouncementDraft $draft = null): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            false,
            $draft,
        );
    }

    public static function text(string $text): self
    {
        return new self($text, false);
    }

    public static function error(string $message): self
    {
        return new self($message, true);
    }
}
