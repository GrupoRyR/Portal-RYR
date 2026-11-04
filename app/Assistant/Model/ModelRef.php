<?php

namespace App\Assistant\Model;

/**
 * «anthropic:claude-haiku-5-5»: a provider of `assistant.providers` and a
 * model of it. A reference without a provider is Anthropic's, so the
 * older settings (`claude-haiku-5-5`) keep working.
 */
final readonly class ModelRef
{
    public const DEFAULT_PROVIDER = 'anthropic';

    public function __construct(
        public string $provider,
        public string $model,
    ) {}

    public static function parse(string $reference): self
    {
        $reference = trim($reference);

        if (str_contains($reference, ':')) {
            [$provider, $model] = explode(':', $reference, 2);

            return new self(strtolower(trim($provider)) ?: self::DEFAULT_PROVIDER, trim($model));
        }

        return new self(self::DEFAULT_PROVIDER, $reference);
    }

    /** «anthropic:claude-haiku-5-5», the key of the price table. */
    public function key(): string
    {
        return $this->provider.':'.$this->model;
    }
}
