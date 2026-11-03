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
| Deployment notes: docs/despliegue.md §1.23.
|
*/

return [

    // The hard kill switch. Only true, 1, on or yes turn it on.
    'enabled' => filter_var(env('PORTAL_ASSISTANT_ENABLED', false), FILTER_VALIDATE_BOOL),

    // The model of every question.
    'model' => env('PORTAL_ASSISTANT_MODEL', 'claude-opus-5-5'),

    // Asked again, with the same request, when the main model's answer
    // ends with `refusal`. Empty: no second try.
    'fallback_model' => env('PORTAL_ASSISTANT_FALLBACK_MODEL', 'claude-opus-4-8'),

    // low, medium, high, xhigh or max (App\Assistant\AssistantSettings
    // falls back to low on anything else).
    'effort' => env('PORTAL_ASSISTANT_EFFORT', 'low'),

    // Output ceiling of each model call (thinking included).
    'max_tokens' => (int) env('PORTAL_ASSISTANT_MAX_TOKENS', 16000),

    // Seconds the server waits for one model call before giving up.
    'timeout' => (int) env('PORTAL_ASSISTANT_TIMEOUT', 60),

    // Questions per account and day (Bogotá), counted from Auditoría.
    'daily_questions' => (int) env('PORTAL_ASSISTANT_DAILY_QUESTIONS', 40),

    // Questions (with their answers) kept in the session and sent back as
    // context; older ones are dropped.
    'history_turns' => (int) env('PORTAL_ASSISTANT_HISTORY_TURNS', 10),

    // Characters of one question.
    'question_max_length' => 1000,

    // Rounds of tool calls in one question before the assistant stops.
    'max_tool_rounds' => 6,

];
