<?php

namespace App\Tenancy;

use DomainException;

/**
 * Raised when a save would leave a Microsoft tenant with more than one
 * primary company, which would make the shared-tenant fallback ambiguous.
 */
class DuplicatePrimaryCompanyException extends DomainException
{
    public static function forTenant(string $tenantId): self
    {
        return new self("Ya existe una empresa principal para el inquilino {$tenantId}. Desmárquela antes de asignar otra.");
    }
}
