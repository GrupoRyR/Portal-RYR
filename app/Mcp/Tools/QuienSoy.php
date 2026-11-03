<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\EnsureMcpConnection;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `quien_soy`: the accounts this connection may read for right now (the
 * ones `mcp.connection` left on the request: confirmed when connecting and
 * still usable), the approving account first. Name, email and company of
 * each, nothing else. A placeholder until C3's tools.
 */
#[Name('quien_soy')]
#[Description('Devuelve las cuentas del Portal RYR que puede usar esta conexión, primero la que la aprobó: nombre, correo y empresa de cada una.')]
#[IsReadOnly]
class QuienSoy extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $approver = $request->user();
        $accounts = request()->attributes->get(EnsureMcpConnection::ACCOUNTS);

        if (! $approver instanceof User || ! $accounts instanceof Collection) {
            return Response::error('No hay una cuenta del portal en esta conexión.');
        }

        return Response::structured([
            'cuentas' => $accounts
                ->sortBy(fn (User $account) => $account->is($approver) ? 0 : 1)
                ->map(fn (User $account) => [
                    'nombre' => $account->name,
                    'correo' => $account->email,
                    'empresa' => Company::displayName($account->company),
                ])
                ->values()
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
