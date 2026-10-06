<?php

namespace Tests\Unit\View;

use App\Models\Company;
use App\View\Home\QuickLink;
use App\View\Home\QuickLinks;
use Tests\TestCase;

class QuickLinksTest extends TestCase
{
    public function test_without_a_company_only_the_microsoft_365_defaults_are_listed(): void
    {
        $links = QuickLinks::for(null);

        $this->assertSame(['Outlook', 'Teams', 'OneDrive'], array_map(fn (QuickLink $link) => $link->label, $links));
        $this->assertSame(['outlook', 'teams', 'onedrive'], array_map(fn (QuickLink $link) => $link->icon, $links));

        foreach ($links as $link) {
            $this->assertStringStartsWith('https://', $link->url);
        }
    }

    public function test_company_links_follow_the_defaults(): void
    {
        $company = new Company(['quick_links' => [
            ['label' => 'SharePoint', 'url' => 'https://segurex.sharepoint.com'],
            ['label' => 'Nómina', 'url' => 'https://nomina.segurex.com.co'],
        ]]);

        $links = QuickLinks::for($company);

        $this->assertSame(['Outlook', 'Teams', 'OneDrive', 'SharePoint', 'Nómina'], array_map(fn (QuickLink $link) => $link->label, $links));
        $this->assertSame('sharepoint', $links[3]->icon);
        $this->assertSame('link', $links[4]->icon);
        $this->assertSame('https://segurex.sharepoint.com', $links[3]->url);
    }

    public function test_a_company_link_with_a_default_name_replaces_its_url(): void
    {
        $company = new Company(['quick_links' => [
            ['label' => 'outlook', 'url' => 'https://outlook.office.com/mail/'],
        ]]);

        $links = QuickLinks::for($company);

        $this->assertCount(3, $links);
        $this->assertSame('Outlook', $links[0]->label);
        $this->assertSame('https://outlook.office.com/mail/', $links[0]->url);
    }

    public function test_links_without_a_label_or_an_https_url_are_skipped(): void
    {
        $company = new Company(['quick_links' => [
            ['label' => 'Script', 'url' => 'javascript:alert(1)'],
            ['label' => 'Plano', 'url' => 'http://intranet.segurex.com.co'],
            ['label' => '  ', 'url' => 'https://segurex.com.co'],
            ['url' => 'https://segurex.com.co'],
            'not-an-array',
        ]]);

        $this->assertCount(3, QuickLinks::for($company));
    }
}
