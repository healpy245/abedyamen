<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Services\AiChatbot\FarzDashboardService;
use App\Services\AiChatbot\MansourBotService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FarzDashboardController extends Controller
{
    public function __construct(
        protected FarzDashboardService $dashboard,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        if ($user->isMowahada()) {
            app(MansourBotService::class)->ensureAppsBot($user);
            app(MansourBotService::class)->ensureDimaBot($user);
            app(MansourBotService::class)->ensureInviteBot($user);
            app(MansourBotService::class)->ensureYouthInviteBot($user);
        }
        app(MansourBotService::class)->shareInviteBot();
        abort_if($this->dashboard->botsFor($user)->isEmpty(), 404);

        return view('ai-chatbot.farz.dashboard', [
            'data' => $this->dashboard->build($user),
        ]);
    }
}
