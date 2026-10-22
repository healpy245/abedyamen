<?php

declare(strict_types=1);

use App\Http\Controllers\OfficeVision\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/4545', [StatusController::class, 'index'])->name('officevision.gate');
Route::post('/internal/4545', [StatusController::class, 'unlock'])->name('officevision.unlock');
