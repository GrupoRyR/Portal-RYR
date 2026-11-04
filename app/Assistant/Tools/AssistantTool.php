<?php

namespace App\Assistant\Tools;

use App\Models\User;

/**
 * One tool the assistant's model may call, always for the signed-in
 * account (App\Assistant\AssistantTools). Read only, except
 * `borrador_de_anuncio`, which only proposes a draft for the page: no tool
 * writes to the database.
 */
interface AssistantTool
{
    /** The name the model calls it by («mis_tareas»). */
    public function name(): string;

    /** What it does, for the model, in Spanish. */
    public function description(): string;

    /**
     * JSON Schema of its input (an object).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /** Whether the API must keep its input exactly to the schema (`strict: true`). */
    public function strict(): bool;

    /** Whether this account gets the tool at all. */
    public function availableTo(User $user): bool;

    /**
     * @param  array<string, mixed>  $input  As the model sent it: validated here.
     */
    public function run(User $user, array $input): ToolOutcome;
}
