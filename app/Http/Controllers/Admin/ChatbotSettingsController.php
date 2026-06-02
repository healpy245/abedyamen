<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateChatbotSettingsRequest;
use App\Services\ChatbotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ChatbotSettingsController extends Controller
{
    public function __construct(
        private readonly ChatbotService $chatbotService
    ) {
    }

    public function index(): View
    {
        return view('admin.chatbot-settings', [
            'settings' => $this->chatbotService->getSettings(),
        ]);
    }

    public function update(UpdateChatbotSettingsRequest $request): RedirectResponse
    {
        $this->chatbotService->updateSettings($request->validated());

        return redirect()
            ->route('admin.chatbot.settings.index')
            ->with('success', 'Chatbot settings updated successfully.');
    }
}
