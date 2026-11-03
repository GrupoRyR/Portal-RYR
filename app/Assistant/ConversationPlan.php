<?php

namespace App\Assistant;

use App\Assistant\Tools\BorradorDeAnuncio;
use App\Models\User;

/**
 * What a conversation is frozen with when it starts: the model (the
 * editor model for accounts with `borrador_de_anuncio`, the reader model
 * otherwise), the effort, the static system prompt and the tool list. Its
 * fingerprint is kept with the conversation; when it no longer matches
 * (the role changed, a setting or a tool description changed in a
 * deploy), the conversation starts over instead of re-rendering the
 * prefix under an append-only history (preserved thinking, prompt cache).
 *
 * max_tokens is not part of it: it does not touch the cached prefix.
 */
final readonly class ConversationPlan
{
    /**
     * @param  list<array{name: string, description: string, inputSchema: array<string, mixed>, strict?: bool}>  $tools
     */
    public function __construct(
        public string $model,
        public string $effort,
        public int $maxTokens,
        public string $system,
        public array $tools,
        public bool $editor,
    ) {}

    public static function for(User $user, AssistantSettings $settings, AssistantTools $tools): self
    {
        $definitions = $tools->definitions($user);
        $editor = in_array(BorradorDeAnuncio::NAME, array_column($definitions, 'name'), true);

        return new self(
            model: $editor ? $settings->editorModel : $settings->model,
            effort: $settings->effort,
            maxTokens: $editor ? $settings->maxTokensDraft : $settings->maxTokensChat,
            system: SystemPrompt::STABLE,
            tools: $definitions,
            editor: $editor,
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(
            [$this->model, $this->effort, $this->system, $this->tools],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
