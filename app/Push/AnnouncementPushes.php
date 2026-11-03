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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phone notices of the announcements published with «Enviar al celular»
 * (odd/tasks/avisos.md T2, R1, R2). No queue: the cron
 * (portal:send-announcement-pushes, every minute) sends a bounded batch
 * ({@see send()}).
 *
 * Who gets one: every device (App\Models\PushSubscription) of the accounts
 * that see the announcement on the home right now, by the same rule as
 * Announcement::scopeVisibleTo(): published, and addressed to the
 * account's company (every company when it is for all); accounts without
 * a company, the emergency one included, see none. Narrower than the home
 * in two ways (PushSubscriptions::reachable()): the company must be active
 * and the account must have signed in within push.max_inactive_days, so a
 * phone that left the company stops getting notices. Seeing is per
 * account, so a person with linked accounts gets it on the devices of the
 * account that sees it.
 *
 * Each device is recorded (App\Models\PushDelivery) before the push service
 * is called, and the unique pair (announcement, endpoint) keeps it from a
 * second notice, whatever happened to the first. Once a run finds nobody
 * left, the announcement is finished (`push_finished_at`): a device added
 * later does not get an old notice, unless someone ticks the box again or
 * changes its companies. A device the push service no longer knows
 * (404/410) is deleted.
 *
 * A failure of the whole run never costs a device its notice: nothing is
 * claimed until the sender has started (VAPID settings the library
 * accepts), the payload and icons are built before claiming, and when no
 * notice of a chunk gets any HTTP answer (the network is down) those
 * claims are dropped and the run stops, to try again next minute. One
 * broken announcement is logged and skipped.
 */
class AnnouncementPushes
{
    /** Notices claimed and sent together; the time budget is checked between chunks. */
    public const CHUNK = 20;

    /** Characters of the text in the notice. */
    public const BODY_LENGTH = 140;

    /**
     * Largest payload this builds (the title and text at their limits, four
     * bytes a character); web push takes 4078 bytes before encryption.
     */
    public const MAX_PAYLOAD_BYTES = 3072;

    /** @var array<int, string> icon URL by company id, for one run */
    private array $icons = [];

    /** @var list<int> ids of the rows claimed and not settled yet, for the current announcement */
    private array $pending = [];

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

        if (! $this->settings->enabled() || ! $this->sender->start()) {
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

            $this->pending = [];

            try {
                [$used, $stop] = $this->sendAnnouncement($announcement, $limit, $deadline, $result);
                $limit -= $used;
            } catch (Throwable $e) {
                $this->settleAfterError($announcement, $e);

                continue;
            }

            if ($stop) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param  array{sent: int, failed: int, gone: int}  $result
     * @return array{int, bool} devices taken from the limit, and whether the run must stop
     */
    private function sendAnnouncement(Announcement $announcement, int $limit, float $deadline, array &$result): array
    {
        // What the box looked like now: an editor ticking it again meanwhile changes it.
        $seen = $announcement->updated_at;
        $base = $this->basePayload($announcement);

        $candidates = $this->candidates($announcement, $limit);
        $exhausted = $candidates->count() < $limit;

        // Every icon before anything is claimed.
        $icons = [];
        foreach ($candidates->pluck('recipient_company_id')->unique() as $companyId) {
            $icons[(int) $companyId] = $this->icon((int) $companyId);
        }

        foreach ($candidates->chunk(self::CHUNK) as $chunk) {
            if (microtime(true) >= $deadline) {
                return [$candidates->count(), false];
            }

            $outcomes = $this->deliver($announcement, $chunk, $base, $icons);

            if ($outcomes === null) {
                // No answer from any push service: try again next minute.
                return [$candidates->count(), true];
            }

            foreach ($outcomes as $outcome) {
                $result[$outcome]++;
            }
        }

        // Fewer devices than asked for: nobody is left for this announcement.
        if ($exhausted) {
            Announcement::query()
                ->whereKey($announcement->id)
                ->whereNull('push_finished_at')
                ->where('push_phone', true)
                ->when($seen === null, fn (Builder $query) => $query->whereNull('updated_at'), fn (Builder $query) => $query->where('updated_at', $seen))
                ->toBase()
                ->update(['push_finished_at' => now()]);
        }

        return [$candidates->count(), false];
    }

    /**
     * The devices that see it and did not get it yet, by id, with the
     * company of their account.
     *
     * @return Collection<int, PushSubscription>
     */
    private function candidates(Announcement $announcement, int $limit): Collection
    {
        return PushSubscriptions::reachable()
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
     * Claims the chunk, sends it and records each outcome. Null when no
     * notice got any HTTP answer: the claims are dropped, as if never tried.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array{title: string, body: string, url: string, tag: string}  $base
     * @param  array<int, string>  $icons
     * @return list<string>|null one of 'sent', 'failed', 'gone' per device sent to
     */
    private function deliver(Announcement $announcement, Collection $subscriptions, array $base, array $icons): ?array
    {
        $claimed = [];
        $messages = [];

        foreach ($subscriptions as $subscription) {
            $delivery = $this->claim($announcement, $subscription);

            if ($delivery === null) {
                continue;
            }

            $this->pending[] = $delivery->id;
            $claimed[(string) $delivery->id] = [$delivery, $subscription];
            $messages[] = new PushMessage(
                key: (string) $delivery->id,
                endpoint: $subscription->endpoint,
                publicKey: $subscription->public_key,
                authToken: $subscription->auth_token,
                contentEncoding: $subscription->content_encoding,
                payload: self::encode([...$base, 'icon' => $icons[(int) $subscription->getAttribute('recipient_company_id')]]),
                topic: $base['tag'],
            );
        }

        if ($messages === []) {
            return [];
        }

        $outcomes = $this->sender->send($messages);

        $unreachable = array_filter(array_keys($claimed), fn (string $key) => ($outcomes[$key] ?? null) === PushSender::UNREACHABLE);

        if (count($unreachable) === count($claimed)) {
            PushDelivery::query()->whereKey($this->pending)->delete();
            $this->pending = [];
            Log::warning('Phone notices got no answer from any push service; the run stops and tries again next minute.', [
                'announcement_id' => $announcement->id,
                'devices' => count($claimed),
            ]);

            return null;
        }

        $result = [];

        foreach ($claimed as $key => [$delivery, $subscription]) {
            $status = match ($outcomes[$key] ?? PushSender::FAILED) {
                PushSender::SENT => PushDelivery::STATUS_SENT,
                PushSender::GONE => PushDelivery::STATUS_GONE,
                // One without an answer among others that got one: tried, so never again.
                default => PushDelivery::STATUS_FAILED,
            };

            $delivery->forceFill(['status' => $status])->save();

            if ($status === PushDelivery::STATUS_GONE) {
                // Only if it was not subscribed again meanwhile (new keys, same endpoint).
                PushSubscription::query()->whereKey($subscription->id)->where('updated_at', $subscription->updated_at)->delete();
            }

            $result[] = $status === PushDelivery::STATUS_GONE ? 'gone' : ($status === PushDelivery::STATUS_SENT ? 'sent' : 'failed');
        }

        $this->pending = [];

        return $result;
    }

    /**
     * Records the device before calling the push service. Null when a row
     * already exists (an overlapping run got there first).
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
     * Something broke while sending one announcement: what was claimed may
     * have reached the device, so it is failed (never sent twice, never left
     * pending), and the run goes on with the next announcement.
     */
    private function settleAfterError(Announcement $announcement, Throwable $e): void
    {
        try {
            if ($this->pending !== []) {
                PushDelivery::query()->whereKey($this->pending)->update(['status' => PushDelivery::STATUS_FAILED]);
            }
        } finally {
            $this->pending = [];
            Log::error('Phone notices of an announcement could not be sent.', [
                'announcement_id' => $announcement->id,
                'exception' => $e::class,
            ]);
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
        $base = $this->basePayload($announcement);

        return self::encode([...$base, 'icon' => $this->icon($companyId)]);
    }

    /**
     * @return array{title: string, body: string, url: string, tag: string}
     */
    private function basePayload(Announcement $announcement): array
    {
        // The cron has no request: links are built from APP_URL.
        $base = rtrim((string) config('app.url'), '/');

        return [
            'title' => Str::limit(AnnouncementListEmails::oneLine($announcement->title), Announcement::TITLE_MAX, '…'),
            'body' => Str::limit(AnnouncementListEmails::oneLine($announcement->body), self::BODY_LENGTH - 1, '…'),
            'url' => $base.route('announcements.show', $announcement, false),
            'tag' => 'anuncio-'.$announcement->id,
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private static function encode(array $payload): string
    {
        $ordered = ['title' => $payload['title'], 'body' => $payload['body'], 'url' => $payload['url'], 'icon' => $payload['icon'], 'tag' => $payload['tag']];

        return json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function icon(int $companyId): string
    {
        if (! isset($this->icons[$companyId])) {
            $base = rtrim((string) config('app.url'), '/');
            $url = $this->pwaIcons->for(Company::query()->find($companyId))->any192;
            // Both live on this site (public/icons, the "public" disk): its path on
            // APP_URL, since the service worker only shows an icon of its own origin.
            $this->icons[$companyId] = $base.'/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        }

        return $this->icons[$companyId];
    }
}
