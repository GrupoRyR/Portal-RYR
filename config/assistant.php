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

    // Models (decided by Jhon), each fixed for the whole conversation:
    // `model` for people who only ask questions, `editor_model` for the
    // conversations of announcement editors (the ones with
    // `borrador_de_anuncio`; empty: the same as `model`).
    'model' => env('PORTAL_ASSISTANT_MODEL', 'claude-haiku-5-5'),
    'editor_model' => env('PORTAL_ASSISTANT_EDITOR_MODEL', 'claude-sonnet-5-5'),

    // Only when the conversation's model ends with `refusal`: the question
    // is asked again as a NEW request (the text of the conversation,
    // without its thinking) to this model. Haiku 5.5 has no server-side
    // fallback, so this one is the portal's own. Empty: no second try.
    'fallback_model' => env('PORTAL_ASSISTANT_FALLBACK_MODEL', 'claude-opus-5-5'),

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
        'claude-haiku-5-5' => [
            // Prompts of 100K tokens or fewer; above, 0.50 / 2.50.
            'input' => 0.10, 'output' => 0.50,
            'cache_write' => 0.125, // verify: 1.25x input
            'cache_read' => 0.01, // verify: 0.1x input
            'long_context' => ['above' => 100000, 'input' => 0.50, 'output' => 2.50, 'cache_write' => 0.625, 'cache_read' => 0.05], // cache rates: verify
        ],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20], // cache_write: verify (1.25x)
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20], // cache_write: verify (1.25x)
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50], // cache rates: verify
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
