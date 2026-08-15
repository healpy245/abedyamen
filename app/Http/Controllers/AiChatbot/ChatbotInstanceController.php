<?php

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\UpdateChatbotInstanceRequest;
use App\Models\AiChatbot\ChatbotInstance;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChatbotInstanceController extends Controller
{
    public function __construct(
        protected AiChatbotInstanceService $instanceService,
        protected ChatbotGreenApiService $greenApiService,
    ) {}

    public function edit(Request $request, ChatbotInstance $instance)
    {
        $this->instanceService->authorizeForUser($instance, $request->user());

        return view('ai-chatbot.instances.edit', [
            'instance' => $instance,
            'greenapiWebhookUrl' => $this->greenApiService->webhookUrl($instance),
        ]);
    }

    public function update(UpdateChatbotInstanceRequest $request, ChatbotInstance $instance): RedirectResponse
    {
        $this->instanceService->authorizeForUser($instance, $request->user());

        $validated = $request->validated();

        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $phonesRaw = trim((string) ($validated['allowed_reply_phones'] ?? ''));
        $phones = $phonesRaw === ''
            ? []
            : array_values(array_unique(array_filter(array_map(
                static fn (string $line): string => trim($line),
                preg_split('/\R+/', $phonesRaw) ?: []
            ))));

        $settings['allowed_reply_phones'] = $phones;

        $instance->update([
            'name' => $validated['name'],
            'system_prompt' => $validated['system_prompt'],
            'greenapi_url' => isset($validated['greenapi_url']) && trim((string) $validated['greenapi_url']) !== ''
                ? trim((string) $validated['greenapi_url'])
                : null,
            'integration_settings' => $settings,
        ]);

        return redirect()
            ->route('ai-chatbot.instances.show', $instance)
            ->with('status', 'Instance updated.');
    }
}
