<?php

namespace App\Agent;

/**
 * What config/agent.php says, checked strictly. Anything doubtful reads as
 * «not configured», and a connection that is not configured does nothing:
 * no button, no token, no manifest.
 */
final class AgentSettings
{
    /** Smallest panel key, in decoded bytes (contract §3.2). */
    public const MIN_KEY_BYTES = 32;

    /**
     * The agent's origin: `https://host` or `https://host:port`, lower case,
     * and nothing else. A path, a query, a fragment, user info or plain http
     * make it null.
     */
    public static function origin(): ?string
    {
        $origin = config('agent.origin');

        if (! is_string($origin)) {
            return null;
        }

        $origin = strtolower(trim($origin));

        return preg_match('~^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?$~', $origin) === 1 ? $origin : null;
    }

    /**
     * The panel key as raw bytes: the base64 text of AGENT_PANEL_KEY, decoded
     * strictly, at least {@see MIN_KEY_BYTES} bytes. Null when absent or weak.
     */
    public static function key(): ?string
    {
        $text = config('agent.panel_key');

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $key = base64_decode(trim($text), true);

        return $key !== false && strlen($key) >= self::MIN_KEY_BYTES ? $key : null;
    }

    /**
     * Valid origin and valid key.
     */
    public static function active(): bool
    {
        return self::origin() !== null && self::key() !== null;
    }

    /**
     * The Portal's own origin for the manifest: AGENT_APP_URL, else APP_URL,
     * with the same strictness as {@see origin()}.
     */
    public static function appUrl(): ?string
    {
        $url = config('agent.app_url') ?: config('app.url');

        if (! is_string($url)) {
            return null;
        }

        $url = strtolower(rtrim(trim($url), '/'));

        return preg_match('~^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?$~', $url) === 1 ? $url : null;
    }
}
