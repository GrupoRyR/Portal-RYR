<?php

namespace App\Assistant\Model;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * The language-model providers of `assistant.providers`, behind the one
 * seam the assistant uses (App\Assistant\Model\AssistantModel): each
 * request goes to the driver of its provider.
 *
 * - `anthropic` (the default): the official SDK (AnthropicModel), with
 *   the Anthropic-only savings: prompt caching, adaptive thinking, effort
 *   and preserved thinking.
 * - `openai_compatible`: Chat Completions over Laravel's HTTP client
 *   (OpenAiCompatibleModel), e.g. DeepSeek. None of those features.
 *
 * A provider that is not configured, has no key or an unknown driver
 * answers ModelUnavailable `not_configured`, never a call.
 */
class ProviderRegistry implements AssistantModel
{
    public const ANTHROPIC = 'anthropic';

    public const OPENAI_COMPATIBLE = 'openai_compatible';

    /** @var array<string, AssistantModel> */
    private array $drivers = [];

    public function __construct(private readonly HttpFactory $http) {}

    public function send(ModelRequest $request): ModelTurn
    {
        return $this->driver($request->provider)->send($request);
    }

    /**
     * Whether the provider exists, has a known driver and a key.
     */
    public function configured(string $provider): bool
    {
        $config = self::config($provider);

        return $config !== null
            && in_array($config['driver'] ?? null, [self::ANTHROPIC, self::OPENAI_COMPATIBLE], true)
            && trim((string) self::key($provider)) !== '';
    }

    /**
     * Whether the provider processes the data outside Anthropic (any
     * driver but `anthropic`, or a provider that is not configured).
     */
    public static function outsideAnthropic(string $provider): bool
    {
        return (self::config($provider)['driver'] ?? null) !== self::ANTHROPIC;
    }

    /**
     * The env variable of the provider's key, for the messages of Configuración.
     */
    public static function keyName(string $provider): string
    {
        return (string) (self::config($provider)['api_key_env'] ?? strtoupper($provider).'_API_KEY');
    }

    private function driver(string $provider): AssistantModel
    {
        if (isset($this->drivers[$provider])) {
            return $this->drivers[$provider];
        }

        $config = self::config($provider);
        $timeout = max(1, (int) config('assistant.timeout'));

        return $this->drivers[$provider] = match ($config['driver'] ?? null) {
            self::ANTHROPIC => new AnthropicModel(self::key($provider), $timeout),
            self::OPENAI_COMPATIBLE => new OpenAiCompatibleModel($this->http, (string) ($config['base_url'] ?? ''), self::key($provider), $timeout),
            default => new class implements AssistantModel
            {
                public function send(ModelRequest $request): ModelTurn
                {
                    throw new ModelUnavailable('not_configured');
                }
            },
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function config(string $provider): ?array
    {
        $config = config('assistant.providers.'.$provider);

        return is_array($config) ? $config : null;
    }

    private static function key(string $provider): ?string
    {
        $config = self::config($provider) ?? [];
        $key = $config['api_key'] ?? null;

        // Anthropic's key lives in services.anthropic.key (ANTHROPIC_API_KEY).
        if (($key === null || $key === '') && ($config['driver'] ?? null) === self::ANTHROPIC) {
            $key = config('services.anthropic.key');
        }

        return is_string($key) ? $key : null;
    }
}
