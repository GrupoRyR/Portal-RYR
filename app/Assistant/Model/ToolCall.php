<?php

namespace App\Assistant\Model;

/**
 * A tool the model asked to run in its turn.
 */
final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {}
}
