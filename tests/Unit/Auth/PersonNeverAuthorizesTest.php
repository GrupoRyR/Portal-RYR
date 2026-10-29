<?php

namespace Tests\Unit\Auth;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Linked accounts share a person, never permissions: roles, groups,
 * favorites, announcements and app access stay per account. Outside the
 * linking code below, no PHP file of app/ or routes/ may look at the
 * person, so a new policy, gate, middleware or access rule that does
 * fails here. Comments are not code and are ignored.
 */
class PersonNeverAuthorizesTest extends TestCase
{
    private const PERSON = '/person_id|->person\b|\bperson\(|linkedAccounts|\bPerson\b|LinkedAccounts/';

    /**
     * The files that link or unlink accounts, and what each may mention:
     * null for anything. The sign-in classes only call the linking
     * service; they never read the person themselves.
     */
    private const ALLOWED = [
        'app/Auth/LinkedAccounts.php' => null,
        'app/Models/Person.php' => null,
        'app/Models/User.php' => null,
        'app/Http/Controllers/Account/LinkedAccountController.php' => ['linkedAccounts'],
        'app/Notifications/LinkedAccountNotifier.php' => ['Person', 'person_id'],
        'app/Audit/SecurityLog.php' => ['person_id'],
        'app/Auth/MicrosoftLogin.php' => ['LinkedAccounts', 'linkedAccounts'],
        'app/Http/Controllers/Auth/MicrosoftLoginController.php' => ['LinkedAccounts', 'linkedAccounts'],
        // Switching company is the one place where the person decides
        // something: which accounts may be switched to. The rule lives in
        // LinkedAccounts::canSwitch(); the controller only calls it, and
        // the switch is still a Microsoft sign-in with the target account.
        'app/Http/Controllers/Account/SwitchAccountController.php' => ['LinkedAccounts', 'linkedAccounts'],
        // The header switcher only lists what LinkedAccounts::switchTargets()
        // (canSwitch) allows; each entry is still a POST to the switch above.
        'app/View/Components/AccountSwitcher.php' => ['LinkedAccounts'],
        // «Cuentas vinculadas» lists the person's accounts and unlinks one;
        // which accounts it may touch is LinkedAccounts::canUnlink().
        'app/Http/Controllers/Account/AccountController.php' => ['LinkedAccounts', 'linkedAccounts'],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function phpFiles(): array
    {
        $files = [];

        foreach (['app', 'routes'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root().'/'.$directory, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::root()) + 1));
                    $files[$path] = [$path];
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function mentions(string $path): array
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(self::root().'/'.$path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        preg_match_all(self::PERSON, $code, $matches);

        return array_values(array_unique($matches[0]));
    }

    #[DataProvider('phpFiles')]
    public function test_only_the_linking_code_looks_at_the_person(string $path): void
    {
        $allowed = array_key_exists($path, self::ALLOWED) ? self::ALLOWED[$path] : [];

        if ($allowed === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame(
            [],
            array_values(array_diff(self::mentions($path), $allowed)),
            "{$path} must not depend on linked accounts: they never authorize anything.",
        );
    }

    public function test_every_allowed_file_still_needs_to_be_allowed(): void
    {
        foreach (array_keys(self::ALLOWED) as $path) {
            $this->assertFileExists(self::root().'/'.$path);
            $this->assertNotSame([], self::mentions($path), "{$path} no longer looks at the person: take it off the list.");
        }
    }
}
