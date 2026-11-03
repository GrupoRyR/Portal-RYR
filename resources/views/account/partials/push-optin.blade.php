{{--
    «Avisos en el celular» on /cuenta (App\Http\Controllers\Account\PushSubscriptionController):
    this device gets a notification of the announcements published with «Enviar al celular».
    Plain JavaScript, no Alpine, inline so it needs no new Vite entry. The state shown is this
    browser's: it asks for permission, subscribes with the VAPID public key ($key) and posts the
    subscription; turning it off unsubscribes and removes it. While on, every visit posts it again,
    so the device follows the account signed in on it.
--}}
<section id="avisos" aria-labelledby="avisos-titulo" data-push-optin
         data-vapid-key="{{ $key }}"
         data-subscribe-url="{{ route('account.push.store') }}"
         data-csrf="{{ csrf_token() }}"
         class="flex scroll-mt-6 flex-col gap-4 rounded-card border border-line bg-surface p-5 md:p-6">
    <div class="flex flex-col gap-2">
        <h2 id="avisos-titulo" class="font-sans text-lg font-bold tracking-normal">Avisos en el celular</h2>
        <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Recibe en este dispositivo una notificación de los anuncios más importantes de tu empresa: solo los que quien publica marca «Enviar al celular». Se activa en cada dispositivo por separado.</p>
        <p class="text-[0.8125rem] leading-relaxed text-fg-muted">En iPhone y iPad solo funciona con el portal instalado en la pantalla de inicio (iOS 16.4 o posterior): en Safari, toca Compartir › «Agregar a inicio», abre el portal desde ese ícono y actívalo aquí.</p>
    </div>

    <p data-push-status role="status" aria-live="polite" class="text-sm font-semibold">Revisando este dispositivo…</p>

    <div class="flex flex-wrap gap-3">
        <button type="button" data-push-enable hidden
                class="inline-flex min-h-touch cursor-pointer items-center self-start rounded-control bg-brand-button px-5 font-semibold text-white disabled:opacity-60">Activar en este dispositivo</button>
        <button type="button" data-push-disable hidden
                class="inline-flex min-h-touch cursor-pointer items-center self-start rounded-control border border-line-strong bg-surface px-5 font-semibold hover:bg-subtle disabled:opacity-60">Desactivar en este dispositivo</button>
    </div>
</section>
<script>
(() => {
    const box = document.querySelector('[data-push-optin]');
    if (!box) return;

    const status = box.querySelector('[data-push-status]');
    const enable = box.querySelector('[data-push-enable]');
    const disable = box.querySelector('[data-push-disable]');
    const url = box.dataset.subscribeUrl;

    const show = (text, on) => {
        status.textContent = text;
        enable.hidden = on !== false;
        disable.hidden = on !== true;
        enable.disabled = disable.disabled = false;
    };

    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    if (!supported) {
        show('Este navegador no puede recibir avisos. En iPhone, instala el portal en la pantalla de inicio y ábrelo desde ahí.', null);
        return;
    }

    // The VAPID public key (base64url) as the bytes PushManager wants.
    const keyBytes = (base64url) => {
        const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    };

    const send = (method, body) => fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': box.dataset.csrf },
        body: JSON.stringify(body),
    }).then((response) => {
        if (!response.ok) throw new Error(String(response.status));
    });

    const post = (subscription) => send('POST', {
        ...subscription.toJSON(),
        contentEncoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
    });

    const registration = () => navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(() => navigator.serviceWorker.ready);

    const refresh = async () => {
        if (Notification.permission === 'denied') {
            show('Bloqueaste las notificaciones del portal en este navegador. Para activarlas, permítelas en los ajustes del navegador y vuelve a esta página.', null);
            return;
        }

        const subscription = await (await registration()).pushManager.getSubscription();
        if (subscription && Notification.permission === 'granted') {
            // Keep the server in step: the device follows the account signed in on it.
            await post(subscription).catch(() => {});
            show('Los avisos están activados en este dispositivo.', true);
        } else {
            show('Los avisos están desactivados en este dispositivo.', false);
        }
    };

    enable.addEventListener('click', async () => {
        enable.disabled = true;
        try {
            if ((await Notification.requestPermission()) !== 'granted') {
                await refresh();
                return;
            }

            const manager = (await registration()).pushManager;
            const options = { userVisibleOnly: true, applicationServerKey: keyBytes(box.dataset.vapidKey) };
            let subscription;
            try {
                subscription = await manager.subscribe(options);
            } catch (error) {
                // Subscribed earlier with other keys: start again with these.
                const old = await manager.getSubscription();
                if (!old) throw error;
                await old.unsubscribe();
                subscription = await manager.subscribe(options);
            }

            await post(subscription);
            show('Listo: los avisos están activados en este dispositivo.', true);
        } catch {
            show('No se pudieron activar los avisos. Inténtalo de nuevo en unos minutos.', false);
        }
    });

    disable.addEventListener('click', async () => {
        disable.disabled = true;
        try {
            const subscription = await (await registration()).pushManager.getSubscription();
            if (subscription) {
                const endpoint = subscription.endpoint;
                await subscription.unsubscribe();
                await send('DELETE', { endpoint }).catch(() => {});
            }
            show('Los avisos están desactivados en este dispositivo.', false);
        } catch {
            show('No se pudieron desactivar los avisos. Inténtalo de nuevo.', true);
        }
    });

    refresh().catch(() => show('No se pudo revisar este dispositivo. Recarga la página para intentarlo de nuevo.', null));
})();
</script>
