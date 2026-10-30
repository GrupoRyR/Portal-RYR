<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\EnsureMcpConnection;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * What the portal's read tools share. Each tool reads once per account of
 * the call, with that account as the viewer, through the portal's own
 * visibility code, and labels what it returns with the account's company.
 *
 * The accounts are those `mcp.connection` (App\Http\Middleware\EnsureMcpConnection)
 * left on the request: confirmed when connecting and still usable
 * (App\Mcp\ConnectionAccounts), the approving account first. A company
 * that is not among them is never read.
 *
 * Dates go out in the app timezone (America/Bogota) as ISO 8601, with a
 * Spanish label beside them. Nothing a tool returns is logged: the
 * per-call audit line (App\Mcp\AuditedCallTool) has only the tool name.
 */
abstract class PortalTool extends Tool
{
    public const NO_ACCOUNTS = 'No hay una cuenta del portal en esta conexión.';

    /**
     * The accounts of the call, the approving one first; null when the
     * request carries none (not a connector call).
     *
     * @return Collection<int, User>|null
     */
    protected function accounts(Request $request): ?Collection
    {
        $approver = $request->user();
        $accounts = request()->attributes->get(EnsureMcpConnection::ACCOUNTS);

        if (! $approver instanceof User || ! $accounts instanceof Collection) {
            return null;
        }

        return $accounts
            ->sortBy(fn (User $account) => $account->is($approver) ? 0 : 1)
            ->values();
    }

    /**
     * «Segurex», «Derivados»: the short name of the account's company, or
     * its name without one.
     */
    protected static function company(User $account): string
    {
        return $account->company?->short_name ?: Company::displayName($account->company);
    }

    /**
     * Midnight today in the app timezone.
     */
    protected static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    protected static function local(CarbonInterface $moment): CarbonImmutable
    {
        return $moment->toImmutable()->setTimezone(config('app.timezone'));
    }

    /**
     * «2026-11-03T09:00:00-05:00».
     */
    protected static function iso(CarbonInterface $moment): string
    {
        return self::local($moment)->toIso8601String();
    }

    /**
     * «Martes 3 de noviembre de 2026, 09:00».
     */
    protected static function momentLabel(CarbonInterface $moment): string
    {
        return Str::ucfirst(self::local($moment)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm'));
    }

    /**
     * Spanish messages for the tools' input rules, naming the field.
     *
     * @return array<string, string>
     */
    protected static function messages(): array
    {
        return [
            'required' => 'Falta el campo :attribute.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'string' => 'El campo :attribute debe ser texto.',
            'between' => 'El campo :attribute debe estar entre :min y :max.',
            'min' => 'El campo :attribute debe ser al menos :min.',
            'max' => 'El campo :attribute admite como máximo :max.',
        ];
    }
}
