<?php

namespace App\Admin;

use App\Models\User;
use Closure;

/**
 * One row of the roles matrix: what each role may do in an admin section
 * and the real authorization check that must agree with it.
 */
final readonly class RoleMatrixSection
{
    /**
     * @param  array<string, bool|string>  $grants  Role => allowed; a string
     *                                              means allowed with that limitation (e.g. «Solo editores»).
     * @param  Closure(User): bool  $check  The gate or policy that enforces the section.
     * @param  bool  $available  False while the section is still to be built.
     */
    public function __construct(
        public string $label,
        public array $grants,
        public Closure $check,
        public bool $available = true,
    ) {}

    public function allows(string $role): bool
    {
        return ($this->grants[$role] ?? false) !== false;
    }

    /**
     * The limitation shown instead of a check mark, if any.
     */
    public function note(string $role): ?string
    {
        $grant = $this->grants[$role] ?? false;

        return is_string($grant) ? $grant : null;
    }

    public function check(User $user): bool
    {
        return (bool) ($this->check)($user);
    }
}
