<?php

declare(strict_types=1);

use App\Http\Controllers\Sales\AppointmentController;
use App\Http\Controllers\Sales\CatalogController;
use App\Http\Controllers\Sales\ContractController;
use App\Http\Controllers\Sales\LeadController;
use App\Http\Controllers\Sales\LeadReportController;
use App\Http\Controllers\Sales\QuoteController;
use Illuminate\Support\Facades\Route;

// The contract link a client opens from WhatsApp: no login, the token is the key.
Route::get('/contract/{token}', [ContractController::class, 'show'])->name('contracts.show');
Route::post('/contract/{token}', [ContractController::class, 'sign'])->middleware('throttle:12,1')->name('contracts.sign');

Route::middleware('auth')
    ->prefix('workspace/agents')
    ->name('workspace.agents.')
    ->group(function (): void {
        Route::get('/sales', [QuoteController::class, 'index'])->name('sales');
        Route::post('/sales', [QuoteController::class, 'store'])->name('sales.store');
        Route::put('/sales/{quote}', [QuoteController::class, 'update'])->name('sales.update');
        Route::post('/sales/{quote}/close-request', [QuoteController::class, 'requestDeal'])->name('sales.request');
        Route::post('/sales/{quote}/convert-lead', [QuoteController::class, 'convertLead'])->name('sales.convert');
        Route::post('/sales/{quote}/whatsapp', [QuoteController::class, 'whatsapp'])->name('sales.whatsapp');
        Route::post('/sales/{quote}/signature/cancel', [QuoteController::class, 'cancelSignature'])->name('sales.signature.cancel');
        Route::get('/sales/files/{file}', [QuoteController::class, 'file'])->name('sales.file');
        Route::delete('/sales/{quote}', [QuoteController::class, 'destroy'])->name('sales.destroy');
        Route::get('/sales/{quote}/panel', [QuoteController::class, 'panel'])->name('sales.panel');
        Route::get('/sales/{quote}/document', [QuoteController::class, 'document'])->name('sales.document');

        // The pages at /leads and /calendar are workspace.agents; these only change things and answer JSON.
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
        Route::post('/leads/import', [LeadController::class, 'import'])->name('leads.import');
        Route::get('/leads/report', [LeadReportController::class, 'show'])->name('leads.report');
        Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::post('/leads/{lead}/notes', [LeadController::class, 'note'])->name('leads.notes');
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

        Route::post('/calendar', [AppointmentController::class, 'store'])->name('calendar.store');
        Route::post('/calendar/import', [AppointmentController::class, 'import'])->name('calendar.import');
        Route::put('/calendar/{appointment}', [AppointmentController::class, 'update'])->name('calendar.update');
        Route::delete('/calendar/{appointment}', [AppointmentController::class, 'destroy'])->name('calendar.destroy');

        Route::get('/packages/{tab?}', [CatalogController::class, 'index'])
            ->whereIn('tab', CatalogController::TABS)
            ->name('packages');
    });
