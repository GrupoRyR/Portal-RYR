<?php

/*
|--------------------------------------------------------------------------
| Portal assistant («Asistente», /asistente)
|--------------------------------------------------------------------------
|
| A role-aware assistant that answers with what the signed-in account
| already sees in the portal (odd/tasks/asistente.md). Off until
| PORTAL_ASSISTANT_ENABLED=true AND a super admin turns on «Asistente» in
| Configuración (App\Assistant\AssistantSwitch). It also needs the API key
| (`services.anthropic.key`, ANTHROPIC_API_KEY): without it the page says
| the assistant is not available and never calls the model.
|
| Token use: the tools and the system prompt are static and cached, the
| history is append-only, and the model, effort and tools are frozen when
| a conversation starts (App\Assistant\ConversationPlan). Changing any of
| these settings starts a new conversation for everyone on their next
| question.
|
| Deployment notes: docs/despliegue.md §1.23.
|
*/

return [

    // The hard kill switch. Only true, 1, on or yes turn it on.
    'enabled' => filter_var(env('PORTAL_ASSISTANT_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Models (decided by Jhon) as «provider:model» of `providers` below
    // (no provider means anthropic), each fixed for the whole
    // conversation; a provider change starts a new one. `model` for people
    // who only ask questions, `editor_model` for the conversations of
    // announcement editors (the ones with `borrador_de_anuncio`; empty:
    // the same as `model`). The PORTAL_ASSISTANT_* names still work.
    'model' => env('ASSISTANT_MODEL', env('PORTAL_ASSISTANT_MODEL', 'anthropic:claude-haiku-5-5')),
    'editor_model' => env('ASSISTANT_EDITOR_MODEL', env('PORTAL_ASSISTANT_EDITOR_MODEL', 'anthropic:claude-sonnet-5-5')),

    // Only when the conversation's model ends with `refusal` (or
    // `content_filter` on an openai_compatible provider): the question is
    // asked again as a NEW request (the text of the conversation, without
    // its thinking) to this model. Haiku 5.5 has no server-side fallback,
    // so this one is the portal's own. Empty: no second try.
    'fallback_model' => env('ASSISTANT_FALLBACK_MODEL', env('PORTAL_ASSISTANT_FALLBACK_MODEL', 'anthropic:claude-opus-5-5')),

    // Provider failover: when the primary model's provider fails (billing,
    // credentials, a missing key, rate limits, overload, 5xx, timeouts or
    // connection, after the driver's retries), the conversation moves to
    // this model for the rest of its life, as a text-only transcript.
    // Empty: no failover. The editors' one falls back to `secondary_model`.
    // Not the refusal fallback above, which is for refusals only.
    'secondary_model' => env('ASSISTANT_SECONDARY_MODEL', ''),
    'secondary_editor_model' => env('ASSISTANT_SECONDARY_EDITOR_MODEL', ''),

    // Minutes the primary is skipped after a billing or credentials
    // failure, and after a rate limit, overload, 5xx or timeout.
    'primary_cooldown_minutes' => (int) env('ASSISTANT_PRIMARY_COOLDOWN_MINUTES', 30),
    'short_cooldown_minutes' => 2,

    // The providers a model reference may name. Drivers: `anthropic` (the
    // official SDK, with prompt caching, adaptive thinking, effort and
    // preserved thinking) and `openai_compatible` (Chat Completions over
    // HTTPS, none of those). `api_key_env` names the variable for the
    // messages of Configuración; the key itself is read here, so config
    // caching keeps working.
    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            // Empty: services.anthropic.key (the same ANTHROPIC_API_KEY).
            'api_key' => null,
            'api_key_env' => 'ANTHROPIC_API_KEY',
        ],
        // DeepSeek's first-party API. Placeholders to verify at
        // api-docs.deepseek.com: the base URL, the model names
        // (deepseek-chat, deepseek-reasoner) and the prices below. Its data
        // is reported to be stored in China: legal review under Ley 1581
        // before using it with employee data (docs/despliegue.md §1.23). A
        // self-hosted or third-party host of the open-weight model works
        // over the same driver with another base_url.
        'deepseek' => [
            'driver' => 'openai_compatible',
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'api_key_env' => 'DEEPSEEK_API_KEY',
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
    ],

    // low, medium, high, xhigh or max (anything else is low).
    'effort' => env('PORTAL_ASSISTANT_EFFORT', 'low'),

    // Output ceiling of each model call, thinking included (on the 5.5 models
    // thinking cannot be turned off, so not too low): `chat` for everyone,
    // `draft` for the conversations of announcement editors.
    'max_tokens' => [
        'chat' => (int) env('PORTAL_ASSISTANT_MAX_TOKENS_CHAT', 2000),
        'draft' => (int) env('PORTAL_ASSISTANT_MAX_TOKENS_DRAFT', 2000),
    ],

    // Seconds the server waits for one model call before giving up.
    'timeout' => (int) env('PORTAL_ASSISTANT_TIMEOUT', 60),

    // Questions per account and day (Bogotá), counted from Auditoría.
    'daily_questions' => (int) env('PORTAL_ASSISTANT_DAILY_QUESTIONS', 40),

    // Questions in one conversation. The history is never cut: when it is
    // full, the page offers «Nueva conversación».
    'conversation_turns' => (int) env('PORTAL_ASSISTANT_CONVERSATION_TURNS', 10),

    // Size of one conversation in the session (its full transcript, tool
    // results and thinking signatures included); over it, it is full too.
    'conversation_max_kb' => 512,

    // Characters of one question.
    'question_max_length' => 1000,

    // Rounds of tool calls in one question before the assistant stops.
    'max_tool_rounds' => 6,

    // Spend cap of the calendar month (Bogotá), in US dollars, computed
    // from each question's usage and the prices below. At or over it no
    // question reaches the API; super admins get one email at 80 % and one
    // at 100 % each month (App\Assistant\Budget\AssistantBudget).
    'monthly_budget_usd' => (float) env('ASSISTANT_MONTHLY_BUDGET_USD', 20),

    // US dollars per million tokens (claude-api skill, shared/models.md and
    // shared/prompt-caching.md, 2026-10). Cache writes are the 5-minute
    // TTL (1.25x input). Rates marked «verify» are derived from the
    // documented multipliers, not listed per model. A model not listed is
    // priced as `default` (the most expensive tier), so the cap stays safe.
    'prices' => [
        'anthropic:claude-haiku-5-5' => [
            // Prompts of 100K tokens or fewer; above, 0.50 / 2.50.
            'input' => 0.10, 'output' => 0.50,
            'cache_write' => 0.125, // verify: 1.25x input
            'cache_read' => 0.01, // verify: 0.1x input
            'long_context' => ['above' => 100000, 'input' => 0.50, 'output' => 2.50, 'cache_write' => 0.625, 'cache_read' => 0.05], // cache rates: verify
        ],
        'anthropic:claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20], // cache_write: verify (1.25x)
        'anthropic:claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20], // cache_write: verify (1.25x)
        'anthropic:claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50], // cache rates: verify
        // DeepSeek: PLACEHOLDERS, verify at api-docs.deepseek.com before
        // relying on the budget. Cache hits are cache reads; it has no
        // cache writes.
        'deepseek:deepseek-chat' => ['input' => 0.27, 'output' => 1.10, 'cache_write' => 0.0, 'cache_read' => 0.07],
        'deepseek:deepseek-reasoner' => ['input' => 0.55, 'output' => 2.19, 'cache_write' => 0.0, 'cache_read' => 0.14],
        'default' => ['input' => 10.00, 'output' => 50.00, 'cache_write' => 12.50, 'cache_read' => 1.00],
    ],

    // Rows each read tool returns to the assistant (the connector keeps its
    // own, larger limits). Never more than the tool's own limit.
    'tool_limits' => [
        'anuncios_recientes' => 5,
        'anuncios_pendientes' => 10,
        'proximas_fechas' => 15,
        'cumpleanos_y_aniversarios' => 15,
        'buscar_personas' => 10,
        'mis_apps' => 30,
        'mis_tareas' => 15,
        'mis_aprobaciones' => 15,
    ],

];
