<?php

namespace Tests\Feature\Home\Concerns;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Helpers of the home block tests: open the home as a user and read one
 * element of it. The test class provides `$company` and `$user`.
 */
trait ReadsTheHome
{
    private function home(string $suffix = '', ?User $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->user)->fresh())->get('/'.$suffix)->assertOk();
    }

    private function xpath(TestResponse $response): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());

        return new \DOMXPath($document);
    }

    /**
     * The markup of the single element whose attribute (optionally with a value) matches.
     */
    private function element(TestResponse $response, string $attribute, ?string $value = null): string
    {
        $xpath = $this->xpath($response);
        $nodes = $xpath->query($value === null ? "//*[@{$attribute}]" : "//*[@{$attribute}=\"{$value}\"]");

        $this->assertSame(1, $nodes->length, "Expected one element with {$attribute}".($value === null ? '' : "=\"{$value}\"").'.');

        return html_entity_decode((string) $xpath->document->saveHTML($nodes->item(0)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function assertNoElement(TestResponse $response, string $attribute, ?string $value = null): void
    {
        $nodes = $this->xpath($response)->query($value === null ? "//*[@{$attribute}]" : "//*[@{$attribute}=\"{$value}\"]");

        $this->assertSame(0, $nodes->length, "Expected no element with {$attribute}".($value === null ? '' : "=\"{$value}\"").'.');
    }

    /**
     * @return list<string>
     */
    private function sectionLinks(TestResponse $response): array
    {
        $xpath = $this->xpath($response);
        $links = [];

        foreach ($xpath->query('//details[@data-sections]//a') as $link) {
            $href = $link->getAttribute('href');
            $this->assertSame(1, $xpath->query('//*[@id="'.substr($href, 1).'"]')->length, "{$href} is not on the page.");
            $links[] = $href;
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openableApp(array $attributes = [], ?Company $company = null): App
    {
        $company ??= $this->company;
        $app = App::factory()->forCompanies($company)->create($attributes);
        AppAccessRule::forCompany($app, $company);

        return $app;
    }
}
