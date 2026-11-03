<?php

namespace App\Push;

/**
 * The VAPID settings of «Avisos en el celular» (config/push.php). The
 * feature is on only with all three values and a «mailto:» or «https://»
 * subject; otherwise nothing shows and nothing is sent.
 */
final class PushSettings
{
    public function enabled(): bool
    {
        return $this->value('public_key') !== null
            && $this->value('private_key') !== null
            && $this->subject() !== null;
    }

    /** The key browsers subscribe with; null while the feature is off. */
    public function publicKey(): ?string
    {
        return $this->enabled() ? $this->value('public_key') : null;
    }

    /**
     * What App\Push\WebPushSender signs with.
     *
     * @return array{subject: string, publicKey: string, privateKey: string}|null
     */
    public function vapid(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return [
            'subject' => (string) $this->subject(),
            'publicKey' => (string) $this->value('public_key'),
            'privateKey' => (string) $this->value('private_key'),
        ];
    }

    private function subject(): ?string
    {
        $subject = $this->value('subject');

        return $subject !== null && (str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://'))
            ? $subject
            : null;
    }

    private function value(string $key): ?string
    {
        $value = config("push.vapid.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
