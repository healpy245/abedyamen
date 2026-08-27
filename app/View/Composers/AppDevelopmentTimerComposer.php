<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use App\Services\AppDevelopment\TaskTimerService;
use Illuminate\View\View;

class AppDevelopmentTimerComposer
{
    public function __construct(
        private readonly TaskTimerService $timers,
    ) {}

    public function compose(View $view): void
    {
        $user = auth()->user();
        $activeTimer = null;

        if ($user !== null) {
            $activeTimer = $this->timers->activeEntryForUser($user);
            if ($activeTimer !== null) {
                $activeTimer->loadMissing(['task.ticket:id,ticket_number,title']);
            }
        }

        $view->with([
            'activeAppDevTimer' => $activeTimer instanceof AppDevelopmentTimeEntry ? $activeTimer : null,
        ]);
    }
}
