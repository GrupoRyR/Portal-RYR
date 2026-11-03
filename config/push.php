<?php

/*
 * «Avisos en el celular»: web push notifications of the announcements
 * published with «Enviar al celular» (App\Push, docs/despliegue.md §1.22).
 *
 * The VAPID pair identifies the portal to the browsers' push services;
 * `php artisan portal:push-keys` makes one. Without the three values the
 * whole feature is off: no control on /cuenta, no checkbox in the editor,
 * nothing sent (App\Push\PushSettings).
 */
return [
    'vapid' => [
        'public_key' => env('PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('PUSH_VAPID_PRIVATE_KEY'),
        // «mailto:» or «https:»: who the push services may contact about the sender.
        'subject' => env('PUSH_VAPID_SUBJECT'),
    ],

    // Push services a subscription may point at (host, or a parent domain
    // of it), so the cron never posts to any other address: Chrome and
    // Android, Firefox, Safari and iPhone, Edge. A browser with another
    // service cannot subscribe until it is added here.
    'allowed_hosts' => [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ],

    // An account that has not signed in for this many days gets no notices,
    // and a daily prune (portal:prune-push-subscriptions) drops its devices:
    // a phone that left the company stops getting its announcements.
    'max_inactive_days' => 90,

    // Notifications per run of the cron (every minute), across announcements.
    'batch' => 100,

    // Seconds for each request to a push service, and for its connection.
    'timeout' => 5,
    'connect_timeout' => 3,

    // A run stops taking new notifications after this many seconds.
    'run_seconds' => 45,

    // How long a push service keeps a notice for a device that is off (12 h).
    'ttl' => 43_200,
];
