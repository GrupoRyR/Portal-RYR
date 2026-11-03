<?php

namespace App\Assistant\Budget;

use App\Assistant\Model\ModelTurn;

/**
 * The cost in US dollars of one model call: each usage field times its
 * price per million tokens (`assistant.prices`): uncached input, output,
 * cache writes and cache reads. A model not in the table is priced as
 * `default`; Haiku 5.5's long-context rates apply when the prompt (all
 * three input fields) is over their threshold.
 */
final class Pricing
{
    public static function cost(ModelTurn $turn, string $model): float
    {
        $prices = self::prices($model, $turn->inputTokens + $turn->cacheCreationInputTokens + $turn->cacheReadInputTokens);

        return ($turn->inputTokens * $prices['input']
            + $turn->outputTokens * $prices['output']
            + $turn->cacheCreationInputTokens * $prices['cache_write']
            + $turn->cacheReadInputTokens * $prices['cache_read']) / 1_000_000;
    }

    /**
     * @return array{input: float, output: float, cache_write: float, cache_read: float}
     */
    public static function prices(string $model, int $promptTokens = 0): array
    {
        $table = config('assistant.prices');
        $row = is_array($table[$model] ?? null) ? $table[$model] : ($table['default'] ?? []);
        $long = $row['long_context'] ?? null;

        if (is_array($long) && $promptTokens > (int) ($long['above'] ?? PHP_INT_MAX)) {
            $row = $long;
        }

        return [
            'input' => (float) ($row['input'] ?? 0),
            'output' => (float) ($row['output'] ?? 0),
            'cache_write' => (float) ($row['cache_write'] ?? 0),
            'cache_read' => (float) ($row['cache_read'] ?? 0),
        ];
    }
}
