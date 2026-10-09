<?php

namespace App\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends mail with Microsoft Graph `sendMail` (JSON format) as the envelope
 * sender's mailbox, with an app-only token: no mailbox password and no
 * SMTP AUTH. Registered as the `graph` mail transport (config/mail.php).
 *
 * Graph answers 202 Accepted with no body; delivery happens afterwards in
 * Exchange Online. Any other answer becomes a TransportException naming the
 * HTTP status and Graph's error code, never the token or the secret.
 *
 * @see https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0
 * @see https://learn.microsoft.com/en-us/graph/outlook-large-attachments
 */
class GraphTransport extends AbstractTransport
{
    /**
     * Files under 3 MB can travel inside one request; larger ones need an
     * upload session on a draft (Mail.ReadWrite), which this transport
     * deliberately does not use.
     */
    public const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    private const SEND_URL = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    public function __construct(
        private readonly Http $http,
        private readonly GraphAccessToken $token,
        private readonly array $missingSettings = [],
        private readonly int $timeout = 15,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'graph';
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->missingSettings !== []) {
            throw new TransportException('The graph mailer is not configured: set '.implode(', ', $this->missingSettings).' in .env.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();
        $payload = $this->payload($email, $envelope);
        // '@' is a valid path character (RFC 3986 pchar); the rest is encoded.
        $url = sprintf(self::SEND_URL, str_replace('%40', '@', rawurlencode($envelope->getSender()->getAddress())));

        try {
            $response = $this->http->withToken($this->token->get())
                ->acceptJson()
                ->timeout($this->timeout)
                ->post($url, $payload);
        } catch (ConnectionException) {
            // No previous exception: its message may echo the request.
            throw new TransportException('Microsoft Graph (graph.microsoft.com) could not be reached.');
        }

        if ($response->successful()) {
            return;
        }

        if ($response->status() === 401) {
            $this->token->forget();
        }

        $code = $response->json('error.code');
        $detail = $response->json('error.message');

        throw new TransportException(sprintf(
            'Microsoft Graph sendMail failed: HTTP %d (%s)%s',
            $response->status(),
            is_string($code) && $code !== '' ? $code : 'no error code',
            is_string($detail) && $detail !== '' ? ': '.mb_strimwidth($detail, 0, 200, '…') : '.',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Email $email, Envelope $envelope): array
    {
        $sender = $envelope->getSender();
        $fromName = '';

        foreach ($email->getFrom() as $address) {
            if (strcasecmp($address->getAddress(), $sender->getAddress()) === 0) {
                $fromName = $address->getName();
            }
        }

        $html = $this->content($email->getHtmlBody());

        $message = array_filter([
            'subject' => (string) $email->getSubject(),
            'body' => $html !== null
                ? ['contentType' => 'HTML', 'content' => $html]
                : ['contentType' => 'Text', 'content' => (string) $this->content($email->getTextBody())],
            'from' => $this->recipient(new Address($sender->getAddress(), $fromName)),
            'toRecipients' => $this->recipients($email->getTo()),
            'ccRecipients' => $this->recipients($email->getCc()),
            'bccRecipients' => $this->recipients($this->bcc($email, $envelope)),
            'replyTo' => $this->recipients($email->getReplyTo()),
            'attachments' => $this->attachments($email),
        ], fn ($value) => $value !== []);

        return ['message' => $message, 'saveToSentItems' => false];
    }

    /**
     * Envelope recipients that are not in To or Cc (Bcc, or recipients
     * added to the envelope only) travel as Bcc, as in Symfony's own API
     * transports.
     *
     * @return list<Address>
     */
    private function bcc(Email $email, Envelope $envelope): array
    {
        $visible = array_map(
            fn (Address $address) => strtolower($address->getAddress()),
            [...$email->getTo(), ...$email->getCc()],
        );

        return array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address) => ! in_array(strtolower($address->getAddress()), $visible, true),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];
        $bytes = 0;

        foreach ($email->getAttachments() as $part) {
            /** @var DataPart $part */
            $body = $part->getBody();
            $bytes += strlen($body);

            if ($bytes > self::MAX_ATTACHMENT_BYTES) {
                throw new TransportException('The attachments exceed 3 MB, the most Microsoft Graph sendMail accepts in one request.');
            }

            $inline = $part->getDisposition() === 'inline';
            $attachment = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => (string) ($part->getFilename() ?? 'attachment'),
                'contentType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'contentBytes' => base64_encode($body),
                'isInline' => $inline,
            ];

            if ($inline && $part->hasContentId()) {
                $attachment['contentId'] = $part->getContentId();
            }

            $attachments[] = $attachment;
        }

        return $attachments;
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<array{emailAddress: array{address: string, name?: string}}>
     */
    private function recipients(array $addresses): array
    {
        return array_values(array_map(fn (Address $address) => $this->recipient($address), $addresses));
    }

    /**
     * @return array{emailAddress: array{address: string, name?: string}}
     */
    private function recipient(Address $address): array
    {
        $emailAddress = ['address' => $address->getAddress()];

        if ($address->getName() !== '') {
            $emailAddress['name'] = $address->getName();
        }

        return ['emailAddress' => $emailAddress];
    }

    /**
     * @param  resource|string|null  $body
     */
    private function content(mixed $body): ?string
    {
        if (is_resource($body)) {
            return (string) stream_get_contents($body, -1, 0);
        }

        return $body;
    }
}
