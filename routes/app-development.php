<?php

use App\Http\Controllers\AppDevelopment\NotificationController;
use App\Http\Controllers\AppDevelopment\ReleaseController;
use App\Http\Controllers\AppDevelopment\TeamController;
use App\Http\Controllers\AppDevelopment\TicketAttachmentController;
use App\Http\Controllers\AppDevelopment\TicketCommentController;
use App\Http\Controllers\AppDevelopment\TicketController;
use App\Http\Controllers\AppDevelopment\TicketUploadController;
use App\Http\Controllers\AppDevelopment\TicketWorkflowController;
use App\Http\Middleware\ExtendUploadTimeout;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'project:app-development'])
    ->prefix('app-development')
    ->name('app-development.')
    ->group(function (): void {
        Route::get('/', [TicketController::class, 'index'])->name('index');

        Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/tickets', [TicketController::class, 'store'])
            ->middleware(ExtendUploadTimeout::class)
            ->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])->name('tickets.edit');
        Route::put('/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');

        Route::post('/uploads/init', [TicketUploadController::class, 'init'])
            ->middleware(ExtendUploadTimeout::class)
            ->name('uploads.init');
        Route::post('/uploads/{uuid}/chunk', [TicketUploadController::class, 'chunk'])
            ->middleware(ExtendUploadTimeout::class)
            ->name('uploads.chunk');
        Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');

        Route::post('/tickets/{ticket}/start-work', [TicketWorkflowController::class, 'startWork'])->name('tickets.start-work');
        Route::post('/tickets/{ticket}/send-to-qa', [TicketWorkflowController::class, 'sendToQa'])->name('tickets.send-to-qa');
        Route::post('/tickets/{ticket}/return-to-development', [TicketWorkflowController::class, 'returnToDevelopment'])->name('tickets.return-to-development');
        Route::post('/tickets/{ticket}/complete', [TicketWorkflowController::class, 'complete'])->name('tickets.complete');
        Route::post('/tickets/{ticket}/assign', [TicketWorkflowController::class, 'assign'])->name('tickets.assign');
        Route::patch('/tickets/{ticket}/priority', [TicketWorkflowController::class, 'updatePriority'])->name('tickets.priority');
        Route::patch('/tickets/{ticket}/status', [TicketWorkflowController::class, 'updateStatus'])->name('tickets.status');
        Route::patch('/tickets/{ticket}/app-types', [TicketWorkflowController::class, 'updateAppTypes'])->name('tickets.app-types');

        Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->name('tickets.comments.store');
        Route::post('/tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])->name('tickets.attachments.store');
        Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'download'])->name('tickets.attachments.download');

        Route::get('/qa', function () {
            return redirect()->route('app-development.index', ['tab' => 'qa']);
        })->name('qa.index');

        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::put('/team/{member}', [TeamController::class, 'update'])->name('team.update');
        Route::post('/notify/open', [TeamController::class, 'notifyOpen'])->name('notify.open');
        Route::post('/notify/qa', [TeamController::class, 'notifyQa'])->name('notify.qa');

        Route::get('/releases', [ReleaseController::class, 'index'])->name('releases.index');
        Route::get('/releases/create', [ReleaseController::class, 'create'])->name('releases.create');
        Route::post('/releases', [ReleaseController::class, 'store'])->name('releases.store');
        Route::get('/releases/{release}', [ReleaseController::class, 'show'])->name('releases.show');
        Route::get('/releases/{release}/download', [ReleaseController::class, 'download'])->name('releases.download');
    });
