<?php

namespace Tests\Feature\Assistant;

use App\Assistant\Model\AssistantModel;
use App\Assistant\Model\ModelRequest;
use App\Assistant\Model\ModelTurn;
use App\Assistant\Model\ModelUnavailable;
use App\Assistant\Model\ToolCall;
use PHPUnit\Framework\Assert;

/**
 * The assistant's model for tests: answers with the queued turns, in
 * order, and remembers every request. Never touches the network.
 */
final class FakeAssistantModel implements AssistantModel
{
    /** @var list<ModelTurn|ModelUnavailable> */
    private array $queue = [];

    /** @var list<ModelRequest> */
    public array $requests = [];

    public function answers(string $text, string $model = 'claude-opus-5-5'): self
    {
        $this->queue[] = new ModelTurn('end_turn', $text, [], 100, 20, $model, [['type' => 'text', 'text' => $text]]);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function callsTool(string $name, array $input = [], string $id = 'toolu_1', string $model = 'claude-opus-5-5'): self
    {
        $this->queue[] = new ModelTurn('tool_use', '', [new ToolCall($id, $name, $input)], 100, 20, $model, [['type' => 'tool_use', 'id' => $id]]);

        return $this;
    }

    public function refuses(string $model = 'claude-opus-5-5'): self
    {
        $this->queue[] = new ModelTurn('refusal', '', [], 50, 0, $model);

        return $this;
    }

    public function fails(string $reason = 'timeout'): self
    {
        $this->queue[] = new ModelUnavailable($reason);

        return $this;
    }

    public function send(ModelRequest $request): ModelTurn
    {
        $this->requests[] = $request;
        Assert::assertNotEmpty($this->queue, 'The fake model got more calls than queued answers.');

        $next = array_shift($this->queue);

        if ($next instanceof ModelUnavailable) {
            throw $next;
        }

        return $next;
    }
}
