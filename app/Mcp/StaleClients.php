<?php

namespace App\Mcp;

use App\Models\McpConnection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * `portal:purge-mcp-clients` (daily, routes/console.php). Client
 * registration is public and `passport:purge` never deletes clients, so a
 * connector client (redirect: claude.ai's callback only) that is more than
 * a day old and never became a connection is deleted, with its codes and
 * tokens. A client with any connection, even revoked, is kept: it is the
 * connection's history.
 */
class StaleClients
{
    public const MAX_AGE_HOURS = 24;

    /**
     * @return int How many clients were deleted.
     */
    public function purge(): int
    {
        $deleted = 0;

        Passport::client()->newQuery()
            ->where('created_at', '<', now()->subHours(self::MAX_AGE_HOURS))
            ->whereNull('owner_id')
            ->whereNotIn('id', McpConnection::query()->select('oauth_client_id'))
            ->chunkById(200, function ($clients) use (&$deleted) {
                foreach ($clients as $client) {
                    if ($client->redirect_uris === [ClaudeRedirect::CALLBACK]) {
                        $this->delete($client);
                        $deleted++;
                    }
                }
            });

        return $deleted;
    }

    private function delete(Client $client): void
    {
        DB::transaction(function () use ($client) {
            $tokens = Passport::token()->newQuery()->where('client_id', $client->getKey());

            Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->delete();
            $tokens->delete();
            Passport::authCode()->newQuery()->where('client_id', $client->getKey())->delete();
            Passport::deviceCode()->newQuery()->where('client_id', $client->getKey())->delete();
            $client->delete();
        });
    }
}
