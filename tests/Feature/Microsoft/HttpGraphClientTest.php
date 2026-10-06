<?php

namespace Tests\Feature\Microsoft;

use App\Microsoft\GraphClient;
use App\Microsoft\HttpGraphClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpGraphClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function client(): GraphClient
    {
        return $this->app->make(GraphClient::class);
    }

    public function test_it_is_the_bound_graph_client(): void
    {
        $this->assertInstanceOf(HttpGraphClient::class, $this->client());
    }

    public function test_profile_reads_name_job_title_and_mail(): void
    {
        Http::fake(['graph.microsoft.com/v1.0/me*' => Http::response([
            'displayName' => 'Ana Pérez',
            'jobTitle' => 'Analista',
            'mail' => 'ana@segurex.com',
        ])]);

        $this->assertSame(
            ['display_name' => 'Ana Pérez', 'job_title' => 'Analista', 'mail' => 'ana@segurex.com'],
            $this->client()->profile('token'),
        );

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token')
            && str_contains(urldecode($request->url()), '$select=displayName,jobTitle,mail'));
    }

    public function test_photo_returns_the_image_bytes(): void
    {
        Http::fake(['graph.microsoft.com/v1.0/me/photo/$value' => Http::response('jpeg-bytes', 200)]);

        $this->assertSame('jpeg-bytes', $this->client()->photo('token'));
    }

    public function test_photo_returns_null_when_the_user_has_none(): void
    {
        Http::fake(['graph.microsoft.com/v1.0/me/photo/$value' => Http::response(['error' => []], 404)]);

        $this->assertNull($this->client()->photo('token'));
    }

    public function test_groups_follow_every_page(): void
    {
        $next = 'https://graph.microsoft.com/v1.0/me/transitiveMemberOf/microsoft.graph.group?$skiptoken=page2';

        Http::fake(['graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => Http::sequence()
            ->push([
                'value' => [['id' => 'g1', 'displayName' => 'APP-PortalAdmin']],
                '@odata.nextLink' => $next,
            ])
            ->push(['value' => [['id' => 'g2', 'displayName' => 'APP-Cartera']]]),
        ]);

        $this->assertSame([
            ['id' => 'g1', 'display_name' => 'APP-PortalAdmin'],
            ['id' => 'g2', 'display_name' => 'APP-Cartera'],
        ], $this->client()->groups('token'));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '$select=id,displayName'));
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '$skiptoken=page2'));
    }

    public function test_failures_raise_an_exception(): void
    {
        Http::fake(['graph.microsoft.com/*' => Http::response([], 500)]);

        $this->expectException(RequestException::class);

        $this->client()->groups('token');
    }
}
