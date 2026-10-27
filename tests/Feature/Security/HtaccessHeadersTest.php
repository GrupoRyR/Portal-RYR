<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Apache does not run in the tests, so these only pin the directives of
 * public/.htaccess that the hosting relies on.
 */
class HtaccessHeadersTest extends TestCase
{
    private function htaccess(): string
    {
        return (string) file_get_contents(public_path('.htaccess'));
    }

    public function test_php_does_not_announce_its_version(): void
    {
        $this->assertStringContainsString('Header always unset X-Powered-By', $this->htaccess());
        $this->assertStringContainsString('Header unset X-Powered-By', $this->htaccess());
    }

    public function test_apache_sends_hsts_only_over_its_own_tls(): void
    {
        $this->assertStringContainsString(
            'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS',
            $this->htaccess(),
        );
    }

    public function test_uploaded_files_are_never_sniffed_or_run(): void
    {
        $this->assertMatchesRegularExpression(
            '!<If "%\{REQUEST_URI\} =~ m#\^/storage/#">\s*Header set X-Content-Type-Options "nosniff"\s*Header set Content-Security-Policy "sandbox"\s*</If>!',
            $this->htaccess(),
        );
    }

    /**
     * cPanel cannot force HTTPS for an addon domain, so .htaccess does it,
     * keeping AutoSSL validation and local hosts on plain HTTP.
     */
    public function test_plain_http_is_redirected_to_https(): void
    {
        $this->assertMatchesRegularExpression(
            '~RewriteCond %\{HTTPS\} !=on\s*RewriteCond %\{REQUEST_URI\} !\^/\\\\\.well-known/\s*RewriteCond %\{HTTP_HOST\} [^\n]*localhost[^\n]*\s*RewriteRule \^ https://%\{HTTP_HOST\}%\{REQUEST_URI\} \[L,R=301\]~',
            $this->htaccess(),
        );
    }
}
