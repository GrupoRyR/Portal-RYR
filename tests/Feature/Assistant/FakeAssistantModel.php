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
 * order, and remembers every request. Turns carry wire-shaped content
 * with a thinking block and its signature, like the real API. Never
 * touches the network.
 */
final class FakeAssistantModel implements AssistantModel
{
    /** @var list<ModelTurn|ModelUnavailable> */
    private array $queue = [];

    /** @var list<ModelRequest> */
    public array $requests = [];

    /** @var array{0: int, 1: int, 2: int, 3: int} input, output, cache write, cache read of each queued turn */
    private array $usage = [100, 20, 0, 0];

    public function withUsage(int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): self
    {
        $this->usage = [$input, $output, $cacheWrite, $cacheRead];

        return $this;
    }

    public function answers(string $text, string $model = 'claude-haiku-5-5', string $signature = 'sig'): self
    {
        return $this->queue('end_turn', $text, [], $model, [self::thinking($signature), ['type' => 'text', 'text' => $text]]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function callsTool(string $name, array $input = [], string $id = 'toolu_1', string $model = 'claude-haiku-5-5'): self
    {
        return $this->queue('tool_use', '', [new ToolCall($id, $name, $input)], $model, [
            self::thinking('sig-'.$id),
            ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input, 'caller' => ['type' => 'direct']],
        ]);
    }

    public function refuses(string $model = 'claude-haiku-5-5'): self
    {
        return $this->queue('refusal', '', [], $model, []);
    }

    public function cutsOff(string $text = 'A medias', bool $inToolCall = false, string $model = 'claude-haiku-5-5'): self
    {
        $calls = $inToolCall ? [new ToolCall('toolu_cut', 'mis_apps', [])] : [];

        return $this->queue('max_tokens', $text, $calls, $model, [['type' => 'text', 'text' => $text]]);
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

    /**
     * @param  list<ToolCall>  $calls
     * @param  list<array<string, mixed>>  $content
     */
    private function queue(string $stop, string $text, array $calls, string $model, array $content): self
    {
        [$input, $output, $write, $read] = $this->usage;
        $this->queue[] = new ModelTurn($stop, $text, $calls, $input, $output, $model, $content, $write, $read);

        return $this;
    }

    /**
     * @return array{type: string, thinking: string, signature: string}
     */
    private static function thinking(string $signature): array
    {
        return ['type' => 'thinking', 'thinking' => '', 'signature' => $signature];
    }
}
