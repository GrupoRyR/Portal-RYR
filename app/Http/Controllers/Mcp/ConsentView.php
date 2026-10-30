<?php

namespace App\Http\Controllers\Mcp;

use Closure;
use Laravel\Passport\Contracts\AuthorizationViewResponse;

/**
 * Passport's consent view with more parameters: the ones Passport passes
 * (client, user, scopes, request, authToken) plus what `$extra` returns for
 * them. `$extra` runs when Passport hands its parameters over, once the new
 * pending authorization is in the session.
 */
final class ConsentView implements AuthorizationViewResponse
{
    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $extra
     */
    public function __construct(
        private readonly AuthorizationViewResponse $view,
        private readonly Closure $extra,
    ) {}

    public function withParameters(array $parameters = []): static
    {
        $this->view->withParameters($parameters + ($this->extra)($parameters));

        return $this;
    }

    public function toResponse($request)
    {
        return $this->view->toResponse($request);
    }
}
