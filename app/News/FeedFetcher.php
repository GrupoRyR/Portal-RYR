<?php

namespace App\News;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Downloads one news feed without letting the URL reach anything but the
 * public internet (the request leaves from the production server, so this
 * is the SSRF boundary of the news line):
 *
 * 1. the URL must pass App\News\FeedUrlPolicy (https, port 443, no user
 *    info, a DNS name);
 * 2. the host is resolved once (App\News\HostResolver) and every address
 *    must pass App\News\AddressPolicy, otherwise nothing is requested;
 * 3. the connection is pinned to the checked address with CURLOPT_RESOLVE
 *    on a fresh, non-reused connection, so DNS cannot change between the
 *    check and the connect (no DNS rebinding); TLS is still verified
 *    against the host name; environment proxies are bypassed;
 * 4. redirects are not followed by the client: up to `news.max_redirects`
 *    are followed here, each one checked again from step 1;
 * 5. `news.timeout` seconds per request; a body over `news.max_bytes`
 *    aborts the transfer (declared or downloaded size) and is refused, and
 *    a gzip or deflate body is decoded here under the same cap.
 *
 * Failures are App\News\FeedFailure codes; the response body never leaves
 * this class on failure.
 */
class FeedFetcher
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @throws FeedFailure
     */
    public function fetch(string $url): string
    {
        $maxRedirects = (int) config('news.max_redirects');

        for ($hop = 0; ; $hop++) {
            $host = FeedUrlPolicy::hostOf($url) ?? throw new FeedFailure(FeedFailure::INVALID_URL);
            $response = $this->request($url, $host, $this->checkedAddress($host));

            if ($response->redirect()) {
                if ($hop >= $maxRedirects) {
                    throw new FeedFailure(FeedFailure::TOO_MANY_REDIRECTS);
                }

                $url = self::target($url, $host, $response->header('Location'));

                continue;
            }

            if (! $response->successful()) {
                throw FeedFailure::status($response->status());
            }

            $body = $response->body();
            $maxBytes = (int) config('news.max_bytes');

            if (strlen($body) > $maxBytes) {
                throw new FeedFailure(FeedFailure::TOO_LARGE);
            }

            return self::decode($body, (string) $response->header('Content-Encoding'), $maxBytes);
        }
    }

    /**
     * The body as sent, or decompressed here with the same size cap: the
     * client never decodes (`decode_content` off), so a small gzip body
     * cannot expand past `news.max_bytes` (a "zip bomb"). Only gzip and
     * deflate (zlib or raw) are accepted, in one layer.
     */
    private static function decode(string $body, string $encoding, int $maxBytes): string
    {
        $encoding = strtolower(trim($encoding));

        if ($encoding === '' || $encoding === 'identity') {
            return $body;
        }

        $mode = match ($encoding) {
            'gzip', 'x-gzip' => ZLIB_ENCODING_GZIP,
            // HTTP "deflate" is zlib-wrapped, but some servers send raw deflate.
            'deflate' => strlen($body) >= 2 && (ord($body[0]) & 0x0F) === 8 && ((ord($body[0]) << 8) | ord($body[1])) % 31 === 0
                ? ZLIB_ENCODING_DEFLATE
                : ZLIB_ENCODING_RAW,
            default => throw new FeedFailure(FeedFailure::UNSUPPORTED_ENCODING),
        };

        $context = @inflate_init($mode);

        if ($context === false) {
            throw new FeedFailure(FeedFailure::UNSUPPORTED_ENCODING);
        }

        $decoded = '';

        // 1 KB of input inflates to about 1 MB at most, so memory stays bounded.
        $chunks = str_split($body, 1024);
        $last = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $piece = @inflate_add($context, $chunk, $index === $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

            if ($piece === false) {
                throw new FeedFailure(FeedFailure::UNSUPPORTED_ENCODING);
            }

            $decoded .= $piece;

            if (strlen($decoded) > $maxBytes) {
                throw new FeedFailure(FeedFailure::TOO_LARGE);
            }
        }

        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw new FeedFailure(FeedFailure::UNSUPPORTED_ENCODING);
        }

        return $decoded;
    }

    /**
     * The address to connect to: the first one, once all of them are public.
     */
    private function checkedAddress(string $host): string
    {
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new FeedFailure(FeedFailure::DNS_FAILED);
        }

        foreach ($addresses as $address) {
            if (! AddressPolicy::isPublic($address)) {
                throw new FeedFailure(FeedFailure::BLOCKED_ADDRESS);
            }
        }

        return $addresses[0];
    }

    private function request(string $url, string $host, string $ip): Response
    {
        $maxBytes = (int) config('news.max_bytes');
        $aborted = false;
        $pinned = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        try {
            return Http::withOptions([
                'allow_redirects' => false,
                // Compressed bodies are decoded by decode(), under the size cap.
                'decode_content' => false,
                'proxy' => ['no' => ['*']],
                'progress' => function ($expected, $downloaded) use ($maxBytes, &$aborted): bool {
                    return $aborted = $aborted || $expected > $maxBytes || $downloaded > $maxBytes;
                },
                'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"],
                    CURLOPT_FRESH_CONNECT => true,
                    CURLOPT_FORBID_REUSE => true,
                ],
            ])
                ->timeout((int) config('news.timeout'))
                ->connectTimeout((int) config('news.connect_timeout'))
                ->withUserAgent((string) config('news.user_agent'))
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->accept('application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.1')
                ->get($url);
        } catch (ConnectionException|HttpClientException|TransferException $exception) {
            throw new FeedFailure(match (true) {
                $aborted => FeedFailure::TOO_LARGE,
                str_contains($exception->getMessage(), 'cURL error 28') => FeedFailure::TIMEOUT,
                default => FeedFailure::CONNECTION_FAILED,
            });
        }
    }

    /**
     * The absolute URL of a Location header, relative to the current URL.
     * It is checked again by the caller like any other URL.
     */
    private static function target(string $current, string $host, ?string $location): string
    {
        $location = trim((string) $location);
        $path = (string) (parse_url($current, PHP_URL_PATH) ?: '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return match (true) {
            $location === '' => throw new FeedFailure(FeedFailure::INVALID_URL),
            preg_match('#\A[a-z][a-z0-9+.-]*:#i', $location) === 1 => $location,
            str_starts_with($location, '//') => 'https:'.$location,
            str_starts_with($location, '/') => "https://{$host}{$location}",
            default => "https://{$host}{$directory}{$location}",
        };
    }
}
