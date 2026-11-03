<?php

namespace App\Push;

use App\Announcements\AnnouncementListEmails;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Pwa\PwaIcons;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phone notices of the announcements published with «Enviar al celular»
 * (odd/tasks/avisos.md T2). No queue: the cron
 * (portal:send-announcement-pushes, every minute) sends a bounded batch
 * ({@see send()}).
 *
 * Who gets one: every device (App\Models\PushSubscription) of the accounts
 * that see the announcement on the home right now, by the same rule as
 * Announcement::scopeVisibleTo(): published, and addressed to the
 * account's company (every company when it is for all); accounts without
 * a company, the emergency one included, see none. Seeing is per account,
 * so a person with linked accounts gets it on the devices of the account
 * that sees it.
 *
 * Each device is recorded (App\Models\PushDelivery) before the push service
 * is called, and the unique pair (announcement, endpoint) keeps it from a
 * second notice, whatever happened to the first. Once a run finds nobody
 * left, the announcement is finished (`push_finished_at`): a device added
 * later does not get an old notice, unless someone ticks the box again.
 * A device the push service no longer knows (404/410) is deleted.
 */
class AnnouncementPushes
{
    /** Notices claimed and sent together; the time budget is checked between chunks. */
    public const CHUNK = 20;

    /** Characters of the text in the notice. */
    public const BODY_LENGTH = 140;

    /** @var array<int, string> icon URL by company id, for one run */
    private array $icons = [];

    public function __construct(
        private readonly PushSettings $settings,
        private readonly PushSender $sender,
        private readonly PwaIcons $pwaIcons,
    ) {}

    /**
     * Sends up to $limit notices (config push.batch), oldest announcement
     * first, and stops taking new ones after config push.run_seconds.
     *
     * @return array{sent: int, failed: int, gone: int}
     */
    public function send(?int $limit = null): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'gone' => 0];

        if (! $this->settings->enabled()) {
            return $result;
        }

        $limit ??= (int) config('push.batch', 100);
        $deadline = microtime(true) + (float) config('push.run_seconds', 45);

        $announcements = Announcement::query()
            ->inStatus(Announcement::STATUS_PUBLISHED)
            ->where('push_phone', true)
            ->whereNull('push_finished_at')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        foreach ($announcements as $announcement) {
            if ($limit <= 0 || microtime(true) >= $deadline) {
                break;
            }

            $candidates = $this->candidates($announcement, $limit);
            $exhausted = $candidates->count() < $limit;
            $limit -= $candidates->count();

            foreach ($candidates->chunk(self::CHUNK) as $chunk) {
                if (microtime(true) >= $deadline) {
                    $exhausted = false;
                    break;
                }

                foreach ($this->deliver($announcement, $chunk) as $outcome) {
                    $result[$outcome]++;
                }
            }

            // Fewer devices than asked for: nobody is left for this announcement.
            if ($exhausted) {
                Announcement::query()->whereKey($announcement->id)->update(['push_finished_at' => now()]);
            }
        }

        return $result;
    }

    /**
     * The devices that see it and did not get it yet, by id, with the
     * company of their account.
     *
     * @return Collection<int, PushSubscription>
     */
    private function candidates(Announcement $announcement, int $limit): Collection
    {
        return PushSubscription::query()
            ->join('users', 'users.id', '=', 'push_subscriptions.user_id')
            ->where('users.is_emergency', false)
            ->whereNotNull('users.company_id')
            ->when(! $announcement->isForAllCompanies(), fn (Builder $query) => $query->whereIn(
                'users.company_id',
                DB::table('announcement_company')->where('announcement_id', $announcement->id)->select('company_id'),
            ))
            ->whereNotIn('push_subscriptions.endpoint_hash', PushDelivery::query()
                ->where('announcement_id', $announcement->id)
                ->select('endpoint_hash'))
            ->orderBy('push_subscriptions.id')
            ->limit($limit)
            ->get(['push_subscriptions.*', 'users.company_id as recipient_company_id']);
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return list<string> one of 'sent', 'failed', 'gone' per device sent to
     */
    private function deliver(Announcement $announcement, Collection $subscriptions): array
    {
        $claimed = [];
        $messages = [];

        foreach ($subscriptions as $subscription) {
            $delivery = $this->claim($announcement, $subscription);

            if ($delivery === null) {
                continue;
            }

            $claimed[(string) $delivery->id] = [$delivery, $subscription];
            $messages[] = new PushMessage(
                key: (string) $delivery->id,
                endpoint: $subscription->endpoint,
                publicKey: $subscription->public_key,
                authToken: $subscription->auth_token,
                contentEncoding: $subscription->content_encoding,
                payload: $this->payload($announcement, (int) $subscription->getAttribute('recipient_company_id')),
                topic: 'anuncio-'.$announcement->id,
            );
        }

        if ($messages === []) {
            return [];
        }

        $outcomes = $this->sender->send($messages);
        $result = [];

        foreach ($claimed as $key => [$delivery, $subscription]) {
            $outcome = $outcomes[$key] ?? PushSender::FAILED;
            $status = match ($outcome) {
                PushSender::SENT => PushDelivery::STATUS_SENT,
                PushSender::GONE => PushDelivery::STATUS_GONE,
                default => PushDelivery::STATUS_FAILED,
            };

            $delivery->forceFill(['status' => $status])->save();

            if ($status === PushDelivery::STATUS_GONE) {
                // Only if it was not subscribed again meanwhile (new keys, same endpoint).
                PushSubscription::query()->whereKey($subscription->id)->where('updated_at', $subscription->updated_at)->delete();
            }

            $result[] = $status === PushDelivery::STATUS_GONE ? 'gone' : ($status === PushDelivery::STATUS_SENT ? 'sent' : 'failed');
        }

        return $result;
    }

    /**
     * Records the device before calling the push service. Null when a row
     * already exists (another run got there first).
     */
    private function claim(Announcement $announcement, PushSubscription $subscription): ?PushDelivery
    {
        try {
            return PushDelivery::query()->create([
                'announcement_id' => $announcement->id,
                'push_subscription_id' => $subscription->id,
                'endpoint_hash' => $subscription->endpoint_hash,
                'status' => PushDelivery::STATUS_PENDING,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * What the service worker shows (resources/js/sw.js): the title, the
     * start of the text on one line, the link to the announcement, the
     * icon of the company of the device's account and a tag. Nothing about
     * the person.
     */
    public function payload(Announcement $announcement, int $companyId): string
    {
        // The cron has no request: links are built from APP_URL.
        $base = rtrim((string) config('app.url'), '/');

        return (string) json_encode([
            'title' => Str::limit(AnnouncementListEmails::oneLine($announcement->title), Announcement::TITLE_MAX, '…'),
            'body' => Str::limit(AnnouncementListEmails::oneLine($announcement->body), self::BODY_LENGTH - 1, '…'),
            'url' => $base.route('announcements.show', $announcement, false),
            'icon' => $this->icon($companyId, $base),
            'tag' => 'anuncio-'.$announcement->id,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function icon(int $companyId, string $base): string
    {
        if (! isset($this->icons[$companyId])) {
            $url = $this->pwaIcons->for(Company::query()->find($companyId))->any192;
            // Both live on this site (public/icons, the "public" disk): its path on
            // APP_URL, since the service worker only shows an icon of its own origin.
            $this->icons[$companyId] = $base.'/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        }

        return $this->icons[$companyId];
    }
}
