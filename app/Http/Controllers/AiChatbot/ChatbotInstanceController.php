<?php

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\StoreChatbotInstanceRequest;
use App\Http\Requests\AiChatbot\UpdateChatbotInstanceRequest;
use App\Models\AiChatbot\ChatbotInstance;
use App\Services\AiChatbot\AiChatbotInstanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChatbotInstanceController extends Controller
{
    public function __construct(
        protected AiChatbotInstanceService $instanceService,
    ) {
    }

    public function store(StoreChatbotInstanceRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $instance = $this->instanceService->createForUser(
            $user,
            $validated['name'],
            $validated['system_prompt'] ?? null,
        );

        return redirect()->route('ai-chatbot.instances.show', $instance);
    }

    public function edit(Request $request, ChatbotInstance $instance)
    {
        $this->instanceService->authorizeForUser($instance, $request->user());

        return view('ai-chatbot.instances.edit', [
            'instance' => $instance,
        ]);
    }

    public function update(UpdateChatbotInstanceRequest $request, ChatbotInstance $instance): RedirectResponse
    {
        $this->instanceService->authorizeForUser($instance, $request->user());

        $validated = $request->validated();

        $instance->update([
            'name' => $validated['name'],
            'system_prompt' => $validated['system_prompt'],
        ]);

        return redirect()
            ->route('ai-chatbot.instances.show', $instance)
            ->with('status', 'Instance updated.');
    }

    public function destroy(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        $remaining = ChatbotInstance::query()
            ->where('user_id', $user->id)
            ->where('id', '!=', $instance->id)
            ->exists();

        if (!$remaining) {
            return back()->withErrors([
                'instance' => 'You must keep at least one chatbot instance.',
            ]);
        }

        $fallback = ChatbotInstance::query()
            ->where('user_id', $user->id)
            ->where('id', '!=', $instance->id)
            ->oldest('id')
            ->first();

        $instance->delete();

        return redirect()->route('ai-chatbot.instances.show', $fallback);
    }
}
