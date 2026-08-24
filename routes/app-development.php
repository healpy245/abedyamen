<?php

use App\Http\Controllers\AppDevelopment\DashboardController;
use App\Http\Controllers\AppDevelopment\QaQueueController;
use App\Http\Controllers\AppDevelopment\ReleaseController;
use App\Http\Controllers\AppDevelopment\TicketAttachmentController;
use App\Http\Controllers\AppDevelopment\TicketCommentController;
use App\Http\Controllers\AppDevelopment\TicketController;
use App\Http\Controllers\AppDevelopment\TicketWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'project:app-development'])
    ->prefix('app-development')
    ->name('app-development.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('index');

        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])->name('tickets.edit');
        Route::put('/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');

        Route::post('/tickets/{ticket}/start-work', [TicketWorkflowController::class, 'startWork'])->name('tickets.start-work');
        Route::post('/tickets/{ticket}/send-to-qa', [TicketWorkflowController::class, 'sendToQa'])->name('tickets.send-to-qa');
        Route::post('/tickets/{ticket}/return-to-development', [TicketWorkflowController::class, 'returnToDevelopment'])->name('tickets.return-to-development');
        Route::post('/tickets/{ticket}/complete', [TicketWorkflowController::class, 'complete'])->name('tickets.complete');
        Route::post('/tickets/{ticket}/assign', [TicketWorkflowController::class, 'assign'])->name('tickets.assign');

        Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->name('tickets.comments.store');
        Route::post('/tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])->name('tickets.attachments.store');
        Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'download'])->name('tickets.attachments.download');

        Route::get('/qa', QaQueueController::class)->name('qa.index');

        Route::get('/releases', [ReleaseController::class, 'index'])->name('releases.index');
        Route::get('/releases/create', [ReleaseController::class, 'create'])->name('releases.create');
        Route::post('/releases', [ReleaseController::class, 'store'])->name('releases.store');
        Route::get('/releases/{release}', [ReleaseController::class, 'show'])->name('releases.show');
        Route::get('/releases/{release}/download', [ReleaseController::class, 'download'])->name('releases.download');
    });
