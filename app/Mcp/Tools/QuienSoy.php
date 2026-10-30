<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `quien_soy`: the accounts this connection may read for right now (the
 * ones `mcp.connection` left on the request: confirmed when connecting and
 * still usable), the approving account first. Name, email and company
 * (its short name, like every other tool) of each, nothing else.
 */
#[Name('quien_soy')]
#[Description('Devuelve las cuentas del Portal RYR que puede usar esta conexión, primero la que la aprobó: nombre, correo y empresa de cada una.')]
#[IsReadOnly]
class QuienSoy extends PortalTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        return Response::structured([
            'cuentas' => $accounts
                ->map(fn (User $account) => [
                    'nombre' => $account->name,
                    'correo' => $account->email,
                    'empresa' => self::company($account),
                ])
                ->all(),
        ]);
    }

    /**
     * No input.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
