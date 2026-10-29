<?php

namespace App\Auth;

use RuntimeException;

/**
 * Two accounts that must not be linked (App\Auth\LinkedAccounts). The
 * message is shown to the user as is, so it is always Spanish and never
 * carries technical details; the reason code only goes to the security log
 * (App\Audit\SecurityLog). A refusal of the Microsoft sign-in to the other
 * account keeps the reason of its App\Auth\LoginFailed.
 */
class LinkRefused extends RuntimeException
{
    public const SAME_ACCOUNT = 'same_account';

    public const EMERGENCY_ACCOUNT = 'emergency_account';

    /** An account without a Microsoft identity (tenant and object ID). */
    public const NOT_MICROSOFT = 'not_microsoft';

    /** A B2B guest of its tenant. */
    public const GUEST_ACCOUNT = 'guest_account';

    /**
     * Not known yet whether it is a guest: no Microsoft sign-in or
     * confirmation since the flag was added.
     */
    public const GUEST_UNKNOWN = 'guest_unknown';

    /** An account whose company is inactive, or that has none. */
    public const COMPANY_INACTIVE = 'company_inactive';

    public const SAME_COMPANY = 'same_company';

    /** The person already has an account in that company. */
    public const COMPANY_ALREADY_LINKED = 'company_already_linked';

    /** Both accounts are already linked, each to another person. */
    public const DIFFERENT_PEOPLE = 'different_people';

    public const RETRY = 'No pudimos vincular la cuenta. Inténtalo de nuevo.';

    private const TENANT_NOT_ALLOWED = 'La organización de esa cuenta no tiene acceso a este portal.';

    private const MESSAGES = [
        self::SAME_ACCOUNT => 'Elegiste la cuenta con la que ya entraste. Elige la cuenta de la otra empresa.',
        self::EMERGENCY_ACCOUNT => 'Las cuentas de emergencia no se pueden vincular.',
        self::NOT_MICROSOFT => 'Solo se pueden vincular cuentas de Microsoft.',
        self::GUEST_ACCOUNT => 'Las cuentas de invitado no se pueden vincular. Usa la cuenta propia de cada empresa.',
        self::GUEST_UNKNOWN => 'Confirma de nuevo tu cuenta y vuelve a intentarlo.',
        self::COMPANY_INACTIVE => 'Una de las empresas no tiene acceso al portal.',
        self::SAME_COMPANY => 'Las dos cuentas son de la misma empresa.',
        self::COMPANY_ALREADY_LINKED => 'Ya tienes una cuenta vinculada de esa empresa.',
        self::DIFFERENT_PEOPLE => 'Esa cuenta ya está vinculada con otras cuentas.',
        'tenant_not_allowed' => self::TENANT_NOT_ALLOWED,
        'no_company' => self::TENANT_NOT_ALLOWED,
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::RETRY);
    }
}
