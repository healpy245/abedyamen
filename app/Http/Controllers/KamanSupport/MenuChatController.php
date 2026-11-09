<?php

declare(strict_types=1);

namespace App\Http\Controllers\KamanSupport;

use App\Http\Controllers\Controller;
use App\Models\KamanSupport\MenuAgentChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Saved AI Menu conversations. Each user only ever sees their own.
 */
class MenuChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $chats = MenuAgentChat::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->limit(50)
            ->get(['id', 'subdomain', 'title', 'updated_at']);

        return response()->json([
            'chats' => $chats->map(fn (MenuAgentChat $chat): array => $this->summary($chat))->all(),
        ]);
    }

    public function show(Request $request, MenuAgentChat $chat): JsonResponse
    {
        abort_unless($chat->user_id === $request->user()->id, 404);

        return response()->json($this->summary($chat) + ['messages' => $chat->messages]);
    }

    /**
     * Creates the chat on its first exchange, then keeps replacing its messages as it grows.
     */
    public function save(Request $request, ?MenuAgentChat $chat = null): JsonResponse
    {
        if ($chat !== null) {
            abort_unless($chat->user_id === $request->user()->id, 404);
        }

        $data = $request->validate([
            'subdomain' => ['required', 'string', 'max:120'],
            'messages' => ['required', 'array', 'min:1', 'max:400'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['present', 'nullable', 'string', 'max:20000'],
        ]);

        $messages = array_values(array_map(static fn (array $row): array => [
            'role' => $row['role'],
            'content' => (string) ($row['content'] ?? ''),
        ], $data['messages']));

        $firstQuestion = collect($messages)->firstWhere('role', 'user')['content'] ?? '';
        $chat ??= new MenuAgentChat(['user_id' => $request->user()->id]);
        $chat->fill([
            'subdomain' => $data['subdomain'],
            'title' => $chat->title ?: (Str::limit(trim(preg_replace('/\s+/', ' ', $firstQuestion) ?? ''), 80, '…') ?: $data['subdomain']),
            'messages' => $messages,
        ])->save();

        return response()->json($this->summary($chat));
    }

    public function destroy(Request $request, MenuAgentChat $chat): JsonResponse
    {
        abort_unless($chat->user_id === $request->user()->id, 404);
        $chat->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array{id: int, subdomain: string, title: string, updated: string}
     */
    private function summary(MenuAgentChat $chat): array
    {
        return [
            'id' => $chat->id,
            'subdomain' => $chat->subdomain,
            'title' => $chat->title,
            'updated' => $chat->updated_at?->diffForHumans() ?? '',
        ];
    }
}
