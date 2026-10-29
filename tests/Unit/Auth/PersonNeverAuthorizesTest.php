<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Linked accounts share a person, never permissions: roles, groups,
 * favorites, announcements and app access stay per account. No policy,
 * gate, middleware or access rule may look at the person.
 */
class PersonNeverAuthorizesTest extends TestCase
{
    /** Code that decides what an account may see or do. */
    private const AUTHORIZATION_CODE = [
        'app/Policies',
        'app/Http/Middleware',
        'app/Catalog',
        'app/Admin',
        'app/Announcements',
        'app/View/Home',
        'app/Providers/AppServiceProvider.php',
        'app/Auth/PortalAdminGroup.php',
        'app/Models/App.php',
        'app/Models/AppAccessRule.php',
        'app/Models/Announcement.php',
        'app/Http/Controllers/AppLaunchController.php',
        'app/Http/Controllers/FavoriteController.php',
        'app/Http/Controllers/AnnouncementController.php',
        'app/Http/Controllers/HomeController.php',
        'app/Livewire/Admin/AppAccessRules.php',
        'app/Livewire/Admin/RoleManager.php',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function authorizationFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        foreach (self::AUTHORIZATION_CODE as $path) {
            $full = $root.'/'.$path;
            $found = is_dir($full) ? self::phpFiles($full) : [$full];

            foreach ($found as $file) {
                $files[substr($file, strlen($root) + 1)] = [$file];
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    #[DataProvider('authorizationFiles')]
    public function test_authorization_code_never_reads_the_person(string $file): void
    {
        $this->assertFileExists($file);

        $this->assertDoesNotMatchRegularExpression(
            '/person_id|->person\b|\bperson\(|linkedAccounts|\bPerson\b|LinkedAccounts/',
            (string) file_get_contents($file),
            'Authorization must never depend on linked accounts.',
        );
    }
}
