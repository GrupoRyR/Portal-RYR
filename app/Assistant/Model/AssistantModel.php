<?php

namespace App\Assistant\Model;

/**
 * One call to the language model behind the assistant: the request goes
 * out, one turn comes back. The tool loop, the refusal fallback and the
 * audit live in App\Assistant\Assistant, so they are tested with a fake
 * model and never touch the network.
 *
 * Production: App\Assistant\Model\AnthropicModel (the official SDK).
 */
interface AssistantModel
{
    /**
     * @throws ModelUnavailable when the API cannot answer (network, limits, credentials).
     */
    public function send(ModelRequest $request): ModelTurn;
}
