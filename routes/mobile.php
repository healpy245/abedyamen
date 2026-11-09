<?php

declare(strict_types=1);

use App\Http\Controllers\Mobile\AgentsController;
use App\Http\Controllers\Mobile\DevelopmentController;
use App\Http\Controllers\Mobile\FinanceController;
use App\Http\Controllers\Mobile\RestaurantController;
use App\Http\Controllers\Mobile\UsersController;
use App\Http\Controllers\Mobile\WorkspaceController;
use App\Http\Controllers\Sales\AppointmentController;
use App\Http\Controllers\Sales\LeadController;
use Illuminate\Support\Facades\Route;

/*
 * What the Kaman Workspace phone app reads. Everything here is GET: the app changes things through
 * the same web routes the browser uses, so the rules live in one place.
 * A door route must also be listed in Door::routeRules(), which is what locks it.
 */
Route::middleware('auth')
    ->prefix('api/mobile/v1')
    ->name('mobile.')
    ->group(function (): void {
        Route::get('/me', [WorkspaceController::class, 'me'])->name('me');
        Route::get('/flash', [WorkspaceController::class, 'flash'])->name('flash');
        Route::get('/overview', [WorkspaceController::class, 'overview'])->name('overview');
        Route::get('/restaurants', [WorkspaceController::class, 'restaurants'])->name('restaurants');
        Route::get('/restaurants/{restaurant}', [RestaurantController::class, 'show'])->name('restaurants.show');

        Route::get('/finance/dashboard', [FinanceController::class, 'dashboard'])->name('finance.dashboard');
        Route::get('/finance/clients', [FinanceController::class, 'clients'])->name('finance.clients');
        Route::get('/finance/clients/{client}', [FinanceController::class, 'client'])->name('finance.clients.show');
        Route::get('/finance/deals', [FinanceController::class, 'deals'])->name('finance.deals');
        Route::get('/finance/storage', [FinanceController::class, 'storage'])->name('finance.storage');

        Route::get('/agents/sales', [AgentsController::class, 'sales'])->name('agents.sales');
        Route::get('/agents/sales/{quote}', [AgentsController::class, 'quote'])->name('agents.sales.show');
        Route::get('/agents/packages', [AgentsController::class, 'packages'])->name('agents.packages');
        Route::get('/agents/leads', [LeadController::class, 'index'])->name('agents.leads');
        Route::get('/agents/calendar', [AppointmentController::class, 'index'])->name('agents.calendar');

        Route::get('/development/options', [DevelopmentController::class, 'options'])->name('development.options');
        Route::get('/development/tickets', [DevelopmentController::class, 'tickets'])->name('development.tickets');
        Route::get('/development/tickets/{ticket}', [DevelopmentController::class, 'ticket'])->name('development.tickets.show');
        Route::get('/development/tasks', [DevelopmentController::class, 'tasks'])->name('development.tasks');
        Route::get('/development/tasks/{task}', [DevelopmentController::class, 'task'])->name('development.tasks.show');
        Route::get('/development/releases', [DevelopmentController::class, 'releases'])->name('development.releases');
        Route::get('/development/reports', [DevelopmentController::class, 'reports'])->name('development.reports');

        Route::get('/users', [UsersController::class, 'index'])
            ->middleware('user-manager')
            ->name('users');
    });
