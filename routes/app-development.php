<?php

use App\Http\Controllers\AppDevelopment\NotificationController;
use App\Http\Controllers\AppDevelopment\ReleaseController;
use App\Http\Controllers\AppDevelopment\ReportController;
use App\Http\Controllers\AppDevelopment\TaskController;
use App\Http\Controllers\AppDevelopment\TaskTimerController;
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

        Route::get('/tickets/{ticket}/tasks/create', [TaskController::class, 'createForTicket'])->name('tickets.tasks.create');
        Route::post('/tickets/{ticket}/tasks', [TaskController::class, 'storeForTicket'])->name('tickets.tasks.store');

        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{task}/timer/start', [TaskTimerController::class, 'start'])->name('tasks.timer.start');
        Route::post('/tasks/{task}/timer/pause', [TaskTimerController::class, 'pause'])->name('tasks.timer.pause');
        Route::post('/tasks/{task}/timer/complete', [TaskTimerController::class, 'complete'])->name('tasks.timer.complete');
        Route::put('/time-entries/{timeEntry}', [TaskTimerController::class, 'updateEntry'])->name('time-entries.update');
        Route::delete('/time-entries/{timeEntry}', [TaskTimerController::class, 'destroyEntry'])->name('time-entries.destroy');

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

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export/tickets', [ReportController::class, 'exportTickets'])->name('reports.export.tickets');
        Route::get('/reports/export/tasks', [ReportController::class, 'exportTasks'])->name('reports.export.tasks');
        Route::get('/reports/export/time', [ReportController::class, 'exportTime'])->name('reports.export.time');
    });
