<?php

namespace App\Microsoft;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * Sends one HTML email with Microsoft Graph `sendMail` from a mailbox of
 * any tenant of the group, with an app-only token of that tenant
 * (AppOnlyToken). The portal's own notices keep using the `graph` mail
 * transport (App\Mail\GraphTransport, one mailbox of the Segurex tenant);
 * this one exists because an Exchange list only accepts senders of its
 * own organization, so each company's email must leave from its tenant.
 *
 * Only graph.microsoft.com is called, redirects are never followed (they
 * would carry the token elsewhere) and a 401 renews the token once. Graph
 * answers 202 Accepted; anything else is a GraphAppException with the HTTP
 * status and Graph's error code, never the token, the body or the message.
 * The application permission is `Mail.Send`, limited to the sender
 * mailboxes with RBAC for Applications in Exchange Online (docs/despliegue.md).
 *
 * @see https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0
 * @see https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac
 */
class TenantMailSender
{
    private const SEND_URL = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private const TIMEOUT = 15;

    /** Graph error codes are identifiers; anything else is not recorded. */
    private const CODE_PATTERN = '/\A[A-Za-z][A-Za-z0-9_.]{0,59}\z/';

    public function __construct(
        private readonly Http $http,
        private readonly AppOnlyToken $tokens,
    ) {}

    /**
     * @throws GraphAppException
     */
    public function send(string $tenantId, string $sender, string $recipient, string $subject, string $html): void
    {
        // '@' is a valid path character (RFC 3986 pchar); the rest is encoded.
        $url = sprintf(self::SEND_URL, str_replace('%40', '@', rawurlencode($sender)));
        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $html],
                'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
            ],
            'saveToSentItems' => true,
        ];

        $response = $this->post($tenantId, $url, $payload);

        if ($response->status() === 401) {
            $this->tokens->forget($tenantId);
            $response = $this->post($tenantId, $url, $payload);
        }

        // A 3xx (redirect not followed) fails here too.
        if ($response->successful()) {
            return;
        }

        $code = $response->json('error.code');

        throw new GraphAppException(
            sprintf('Microsoft Graph sendMail answered HTTP %d.', $response->status()),
            $response->status(),
            graphCode: is_string($code) && preg_match(self::CODE_PATTERN, $code) === 1 ? $code : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws GraphAppException
     */
    private function post(string $tenantId, string $url, array $payload): Response
    {
        $request = $this->http->withToken($this->tokens->for($tenantId))
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->withoutRedirecting();

        try {
            return $request->post($url, $payload);
        } catch (ConnectionException) {
            // No previous exception: its message may echo the request.
            throw new GraphAppException('Microsoft Graph (graph.microsoft.com) could not be reached.');
        }
    }
}
