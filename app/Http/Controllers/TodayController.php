<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->isChatbotOnly()) {
            return redirect($user->afterLoginUrl());
        }

        return redirect()->route('design.modern-light');
    }
}
