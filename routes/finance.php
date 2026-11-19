<?php

declare(strict_types=1);

use App\Http\Controllers\Finance\ClientController;
use App\Http\Controllers\Finance\DashboardController;
use App\Http\Controllers\Finance\DealController;
use App\Http\Controllers\Finance\FeatureController;
use App\Http\Controllers\Finance\InvoiceController;
use App\Http\Controllers\Finance\PaymentController;
use App\Http\Controllers\Finance\StorageController;
use App\Http\Controllers\Finance\TranzilaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'door'])
    ->prefix('workspace/finance')
    ->name('workspace.finance.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('/clients', [ClientController::class, 'index'])->name('clients');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::patch('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::post('/clients/{client}/notify', [ClientController::class, 'notify'])->name('clients.notify');
        Route::patch('/clients/{client}/service', [ClientController::class, 'updateService'])->name('clients.service');
        Route::patch('/clients/{client}/billing', [ClientController::class, 'updateBilling'])->name('clients.billing');
        Route::patch('/clients/{client}/billing/state', [ClientController::class, 'updateBillingState'])->name('clients.billing.state');
        Route::patch('/clients/{client}/mfit', [ClientController::class, 'updateMfit'])->name('clients.mfit');
        Route::post('/clients/{client}/restaurants', [ClientController::class, 'link'])->name('clients.link');
        Route::delete('/clients/{client}/restaurants/{restaurant}', [ClientController::class, 'unlink'])->name('clients.unlink');
        Route::patch('/clients/{client}/hardware', [ClientController::class, 'updateHardware'])->name('clients.hardware');
        Route::put('/clients/{client}/card', [ClientController::class, 'saveCard'])->name('clients.card');
        Route::delete('/clients/{client}/card', [ClientController::class, 'removeCard'])->name('clients.card.remove');

        Route::prefix('/clients/{client}/tranzila')->name('clients.tranzila.')->controller(TranzilaController::class)->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::patch('/email', 'updateEmail')->name('email');
            Route::post('/handshake', 'handshake')->name('handshake');
            Route::post('/card', 'saveCard')->name('card');
            Route::post('/charge', 'charge')->name('charge');
            Route::post('/transactions/{transaction}/refund', 'refund')->name('refund');
            Route::post('/transactions/{transaction}/resolve', 'resolve')->name('resolve');
            Route::post('/documents', 'storeDocument')->name('documents.store');
            Route::post('/documents/{document}/reissue', 'reissueDocument')->name('documents.reissue');
            Route::post('/documents/{document}/credit', 'creditDocument')->name('documents.credit');
            Route::post('/subscriptions', 'storeSubscription')->name('subscriptions.store');
            Route::post('/subscriptions/{subscription}/cancel', 'cancelSubscription')->name('subscriptions.cancel');
        });

        Route::post('/clients/{client}/payments', [PaymentController::class, 'store'])->name('clients.payments.store');
        Route::post('/payments/{payment}/settle', [PaymentController::class, 'settle'])->name('payments.settle');
        Route::get('/payments/{payment}/invoice', [PaymentController::class, 'invoice'])->name('payments.invoice');
        Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
        Route::get('/payment-files/{file}', [PaymentController::class, 'file'])->name('payments.file');

        Route::post('/clients/{client}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

        Route::get('/deals', [DealController::class, 'index'])->name('deals');
        Route::post('/deals/{quote}/confirm', [DealController::class, 'confirm'])->name('deals.confirm');
        Route::post('/deals/{quote}/return', [DealController::class, 'return'])->name('deals.return');
        Route::post('/deals/{quote}/device-payment', [DealController::class, 'payDevices'])->name('deals.device-payment');
        Route::get('/notifications/{notification}', [DealController::class, 'notification'])->name('notifications.open');

        Route::get('/storage', [StorageController::class, 'index'])->name('storage');
        Route::post('/storage', [StorageController::class, 'store'])->name('storage.store');
        Route::patch('/storage/{item}', [StorageController::class, 'update'])->name('storage.update');
        Route::post('/storage/{item}/adjust', [StorageController::class, 'adjust'])->name('storage.adjust');
        Route::delete('/storage/{item}', [StorageController::class, 'destroy'])->name('storage.destroy');
        Route::post('/storage/features', [FeatureController::class, 'store'])->name('storage.features.store');
        Route::patch('/storage/features/{feature}', [FeatureController::class, 'update'])->name('storage.features.update');
        Route::delete('/storage/features/{feature}', [FeatureController::class, 'destroy'])->name('storage.features.destroy');
    });
