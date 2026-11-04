<?php

namespace App\Assistant;

use App\Assistant\Budget\Pricing;
use App\Assistant\Model\ModelTurn;

/**
 * Tokens and cost of one question, added up over its model calls (the
 * fallback's included).
 */
final class Usage
{
    public int $calls = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public int $cacheCreationInputTokens = 0;

    public int $cacheReadInputTokens = 0;

    public float $costUsd = 0.0;

    /**
     * @param  string  $model  «provider:model», the key of the price table.
     */
    public function add(ModelTurn $turn, string $model): void
    {
        $this->calls++;
        $this->inputTokens += $turn->inputTokens;
        $this->outputTokens += $turn->outputTokens;
        $this->cacheCreationInputTokens += $turn->cacheCreationInputTokens;
        $this->cacheReadInputTokens += $turn->cacheReadInputTokens;
        $this->costUsd += Pricing::cost($turn, $model);
    }
}
