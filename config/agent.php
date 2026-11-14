<?php

/*
|--------------------------------------------------------------------------
| Connection to the Agente RYR (the company agent)
|--------------------------------------------------------------------------
|
| The Portal embeds the agent's floating button (<x-agent-panel />), which
| replaces the old «Asistente» button. Contract: CONTRATO_CONEXION_AGENTE.md
| (v0.4) of the agent project; procedure: docs/agente-conexion.md.
|
| Environment variables (none is committed; IT sets them in the server's
| .env, the key is delivered by the agent's administrators):
|
|   AGENT_URL        https origin of the agent, with no path and no trailing
|                    slash, e.g. https://agente.example.com
|   AGENT_PANEL_KEY  the panel key of this app, base64 text of at least 32
|                    random bytes; the Portal signs panel tokens with it and
|                    the agent verifies them with the same value
|   AGENT_APP_URL    https origin of this Portal as the agent reaches it;
|                    defaults to APP_URL (used by /.well-known/agente-ryr.json)
|
| Without a valid AGENT_URL and AGENT_PANEL_KEY the button is not drawn and
| no token is signed (App\Agent\AgentSettings). The manifest needs only
| AGENT_APP_URL or APP_URL (an https origin) and answers 503 without one.
|
*/

return [

    // https origin of the agent; anything else (http, a path, a query) is
    // refused and switches the connection off.
    'origin' => env('AGENT_URL'),

    // Base64 text of the panel key (>= 32 decoded bytes). Never logged.
    'panel_key' => env('AGENT_PANEL_KEY'),

    // Origin of this Portal for the manifest. Falls back to APP_URL.
    'app_url' => env('AGENT_APP_URL'),

    // The app's slug in the agent (contract §9) and the token issuer (`iss`).
    'slug' => 'portal-ryr',

    // Name shown by the agent.
    'name' => 'Portal RYR',

    // companies.slug => company code in the agent. A company that is not
    // listed here gets no token (the agent only knows these two); adding a
    // company to the Portal does not add it here by itself.
    'companies' => [
        'segurex' => 'SGX',
        'derivados' => 'SDC',
    ],

    // The role every account gets in the token unless the Portal's own
    // management check passes for it (ManagementPanel::ABILITY: the
    // management role and current membership of its company's «Gerencia»
    // group).
    'default_role' => 'empleado',

    'management_agent_role' => 'gerencia',

];
