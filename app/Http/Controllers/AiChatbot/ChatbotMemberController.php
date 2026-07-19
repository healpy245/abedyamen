<?php

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\StoreChatbotMemberRequest;
use App\Http\Requests\AiChatbot\UpdateChatbotMemberRequest;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMember;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Services\AiChatbot\AiChatbotMemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChatbotMemberController extends Controller
{
    public function __construct(
        protected AiChatbotInstanceService $instanceService,
        protected AiChatbotMemberService $memberService,
    ) {
    }

    public function index(Request $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->memberService->ensureEnabledForInstance($instance, $user);

        $layout = $this->instanceService->layoutData($user, $instance);

        $members = $instance->members()->latest('id')->get();
        $editingMember = null;

        if ($request->filled('edit')) {
            $editingMember = $members->firstWhere('id', (int) $request->query('edit'));
        }

        return view('ai-chatbot.members.index', array_merge($layout, [
            'members' => $members,
            'editingMember' => $editingMember,
        ]));
    }

    public function store(StoreChatbotMemberRequest $request, ChatbotInstance $instance): RedirectResponse
    {
        $this->memberService->ensureEnabledForInstance($instance, $request->user());

        $this->memberService->createForInstance($instance, $request->validated());

        return redirect()
            ->route('ai-chatbot.instances.members.index', $instance)
            ->with('status', 'Member saved.');
    }

    public function update(UpdateChatbotMemberRequest $request, ChatbotInstance $instance, ChatbotMember $member): RedirectResponse
    {
        $this->memberService->ensureEnabledForInstance($instance, $request->user());
        $this->authorizeMemberForInstance($member, $instance);

        $member->update($request->validated());

        return redirect()
            ->route('ai-chatbot.instances.members.index', $instance)
            ->with('status', 'Member updated.');
    }

    public function destroy(Request $request, ChatbotInstance $instance, ChatbotMember $member): RedirectResponse
    {
        $this->memberService->ensureEnabledForInstance($instance, $request->user());
        $this->authorizeMemberForInstance($member, $instance);

        $member->delete();

        return redirect()
            ->route('ai-chatbot.instances.members.index', $instance)
            ->with('status', 'Member removed.');
    }

    protected function authorizeMemberForInstance(ChatbotMember $member, ChatbotInstance $instance): void
    {
        if ($member->instance_id !== $instance->id) {
            abort(404);
        }
    }
}
