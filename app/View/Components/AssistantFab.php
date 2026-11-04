<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;

/**
 * The floating «Asistente» button (odd/tasks/liquid-glass.md G7), rendered
 * by components/layouts/app on the pages that opt in (`assistant`): the same
 * check as the user menu's link ({@see AssistantLink}), and never on
 * /asistente itself.
 *
 * Without JavaScript it is a link to /asistente. resources/js/header.js
 * loads resources/js/assistant-panel.js on its first click and opens the
 * chat panel, which reads the conversation only then. While the API key is
 * missing it still shows: the panel says the assistant is not available.
 */
class AssistantFab extends AssistantLink
{
    public function shouldRender(): bool
    {
        return parent::shouldRender() && ! request()->routeIs('assistant.*');
    }

    public function render(): View
    {
        // The home screen's bottom bar (phones) stays clear of it.
        return view('components.assistant-fab', ['raised' => request()->routeIs('home')]);
    }
}
