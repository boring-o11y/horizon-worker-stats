<?php

use BoringO11y\HorizonWorkerStats\Http\Controllers\WorkerResourcesController;
use Illuminate\Support\Facades\Route;

Route::get('/api/worker-stats', [WorkerResourcesController::class, 'index'])
    ->name('horizon-worker-stats.index');
