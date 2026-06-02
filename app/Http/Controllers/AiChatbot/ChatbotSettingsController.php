<?php

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\UpdateChatbotSettingsRequest;
use App\Services\AiChatbot\AiChatbotSettingsService;
use Illuminate\Http\RedirectResponse;

class ChatbotSettingsController extends Controller
{
    public function __construct(
        protected AiChatbotSettingsService $settingsService,
    ) {
    }

    public function edit()
    {
        $settings = $this->settingsService->all();

        return view('ai-chatbot.settings', [
            'settings' => $settings,
        ]);
    }

    public function update(UpdateChatbotSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $action = $validated['action'] ?? 'save';

        if ($action === 'reset') {
            $this->settingsService->resetToDefaults();

            return back()->with('status', 'AI Chatbot settings reset to defaults.');
        }

        $this->settingsService->save([
            'system_prompt' => $validated['system_prompt'],
            'chatbot_model' => $validated['chatbot_model'],
            'temperature' => $validated['temperature'],
            'max_tokens' => $validated['max_tokens'],
        ]);

        return back()->with('status', 'AI Chatbot settings saved.');
    }
}

