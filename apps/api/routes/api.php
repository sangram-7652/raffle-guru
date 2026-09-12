<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Infrastructure-only for now. Domain routes (competitions, orders,
| tickets, payments, draws, ...) are added in their own phases.
|
*/

Route::prefix('v1')->group(function () {
    Route::get('health', [HealthController::class, 'index']);
    Route::get('health/status', [HealthController::class, 'status']);
});
