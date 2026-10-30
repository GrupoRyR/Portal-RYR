<?php

namespace App\Mcp\Tools;

use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `quien_soy`: the account that approved the connection, and nothing
 * else (name, email, company). A placeholder until C3's tools.
 */
#[Name('quien_soy')]
#[Description('Devuelve la cuenta del Portal RYR con la que se aprobó esta conexión: nombre, correo y empresa.')]
#[IsReadOnly]
class QuienSoy extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('No hay una cuenta del portal en esta conexión.');
        }

        return Response::structured([
            'nombre' => $user->name,
            'correo' => $user->email,
            'empresa' => Company::displayName($user->company),
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
