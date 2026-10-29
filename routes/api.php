<?php

use App\Http\Controllers\Api\AppCatalogController;
use App\Http\Controllers\Api\AppStatusController;
use App\Http\Controllers\Api\WorkItemController;
use App\Models\Integration;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Route;

/*
| API for external systems (brief §6b, docs/api.md), under /api/v1.
|
| Every route needs the Bearer token of an App\Models\Integration
| (`auth:sanctum` + `integration`: not revoked, allowed IP) and is rate
| limited per token (`throttle:api`, AppServiceProvider). Each endpoint
| then needs its own ability. Errors are always JSON (bootstrap/app.php).
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'integration', 'throttle:api'])
    ->name('api.v1.')
    ->group(function () {
        Route::get('/apps', AppCatalogController::class)
            ->middleware('abilities:'.Integration::CATALOG_READ)
            ->name('apps.index');

        Route::put('/apps/{app:slug}/status', AppStatusController::class)
            ->middleware('abilities:'.Integration::STATUS_WRITE)
            ->name('apps.status');

        // Tasks and approvals (docs/contrato-tareas-aprobaciones.md §3):
        // any other `external_id` matches no route, so it answers 404.
        Route::put('/work-items/{externalId}', [WorkItemController::class, 'update'])
            ->where('externalId', WorkItem::EXTERNAL_ID_PATTERN)
            ->middleware('abilities:'.Integration::WORK_WRITE)
            ->name('work-items.update');

        Route::delete('/work-items/{externalId}', [WorkItemController::class, 'destroy'])
            ->where('externalId', WorkItem::EXTERNAL_ID_PATTERN)
            ->middleware('abilities:'.Integration::WORK_WRITE)
            ->name('work-items.destroy');
    });
