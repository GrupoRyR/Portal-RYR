<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Pwa\ServiceWorkerScript;
use Illuminate\Http\Response;

/**
 * /sw.js: the service worker, at the site root so its scope is the whole
 * portal. Browsers check it for updates on navigation; no-cache makes
 * them always ask (a new build changes its bytes and its caches).
 */
class ServiceWorkerController extends Controller
{
    public function __invoke(ServiceWorkerScript $script): Response
    {
        return response($script->render(), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
