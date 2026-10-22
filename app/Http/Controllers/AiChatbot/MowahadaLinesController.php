<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\MowahadaLine;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\MansourLines;
use App\Services\AiChatbot\MowahadaLinePool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MowahadaLinesController extends Controller
{
    public function __construct(
        protected ChatbotAuthorizationService $authz,
        protected MowahadaLinePool $pool,
    ) {}

    public function index(Request $request): View
    {
        $instance = $this->gate($request, false);

        return view('ai-chatbot.farz.numbers', [
            'instance' => $instance,
            'lines' => MowahadaLine::query()->orderBy('id')->get(),
            'usage' => $this->pool->usage(),
            'owners' => $this->pool->owners(),
            'bots' => $this->pool->mowahadaBots()
                ->filter(fn (ChatbotInstance $bot): bool => $this->authz->can($request->user(), $bot, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS))
                ->values(),
            'canManageSettings' => $this->authz->can($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS),
            'canControlBot' => $this->authz->can($request->user(), $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $instance = $this->gate($request, true);
        if (is_array($request->input('ids')) || is_array($request->input('tokens'))) {
            return $this->storeByToken($request, $instance);
        }
        if (is_array($request->input('links'))) {
            $request->validate([
                'links' => ['array', 'max:80'],
                'links.*' => ['nullable', 'string', 'max:2000'],
            ]);
            $parts = array_map('strval', $request->input('links'));
            $errorKey = 'links';
        } else {
            $validated = $request->validate([
                'urls' => ['required', 'string', 'max:20000'],
            ]);
            $parts = preg_split('/\r\n|\r|\n/', $validated['urls']) ?: [];
            $errorKey = 'urls';
        }
        [$urls, $bad] = $this->pool->extract($parts);
        if ($bad !== [] || $urls === []) {
            return back()
                ->withInput()
                ->withErrors([$errorKey => __('chatbot.mansour.line_url_invalid')]);
        }
        if (count($urls) > 80 || MowahadaLine::query()->count() + count($urls) > 80) {
            return back()
                ->withInput()
                ->withErrors(['urls' => __('chatbot.mansour.line_too_many')]);
        }

        $this->pool->remember($urls);

        return redirect()
            ->route('ai-chatbot.farz.numbers', ['bot' => $instance->id])
            ->with('status', 'تمت إضافة الأرقام إلى المصدر.');
    }

    private function storeByToken(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $request->validate([
            'ids' => ['array', 'max:80'],
            'ids.*' => ['nullable', 'string', 'max:2000'],
            'tokens' => ['array', 'max:80'],
            'tokens.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $ids = array_map('strval', $request->input('ids', []));
        $tokens = array_map('strval', $request->input('tokens', []));
        $count = max(count($ids), count($tokens));
        $urls = [];
        for ($i = 0; $i < $count; $i++) {
            $id = trim($ids[$i] ?? '');
            $token = trim($tokens[$i] ?? '');
            if ($id === '' && $token === '') {
                continue;
            }
            $url = $this->pool->sendUrl($id, $token);
            if ($url === null) {
                return back()
                    ->withInput()
                    ->withErrors(['tokens' => __('chatbot.mansour.line_token_invalid')]);
            }
            $urls[] = $url;
        }
        if ($urls === []) {
            return back()
                ->withInput()
                ->withErrors(['tokens' => __('chatbot.mansour.line_token_invalid')]);
        }

        $known = MowahadaLine::query()->pluck('wa_instance')->all();
        $fresh = 0;
        foreach ($urls as $url) {
            $id = preg_match('#waInstance(\d+)#', $url, $match) === 1 ? $match[1] : '';
            if ($id === '' || ! in_array($id, $known, true)) {
                $fresh++;
            }
        }
        if (count($urls) > 80 || MowahadaLine::query()->count() + $fresh > 80) {
            return back()
                ->withInput()
                ->withErrors(['tokens' => __('chatbot.mansour.line_too_many')]);
        }

        $error = $this->pool->connect($urls);
        if ($error !== null) {
            return back()
                ->withInput()
                ->withErrors(['tokens' => __($error)]);
        }

        return redirect()
            ->route('ai-chatbot.farz.numbers', ['bot' => $instance->id])
            ->with('status', 'تمت إضافة الأرقام إلى المصدر.');
    }

    public function destroy(Request $request, MowahadaLine $line): RedirectResponse
    {
        $instance = $this->gate($request, true);
        $this->pool->forget($line);

        return redirect()
            ->route('ai-chatbot.farz.numbers', ['bot' => $instance->id])
            ->with('status', 'تم حذف الرقم من المصدر ومن بوتات الموحدة.');
    }

    public function check(Request $request): RedirectResponse
    {
        $instance = $this->gate($request, true);
        $count = $this->pool->refresh();

        return redirect()
            ->route('ai-chatbot.farz.numbers', ['bot' => $instance->id])
            ->with('status', 'تم تحديث حالة الأرقام. المحدّث: '.$count.'. إذا تغيّر رقم واتساب يظهر الرقم الجديد.');
    }

    public function update(Request $request, MowahadaLine $line): RedirectResponse
    {
        $instance = $this->gate($request, true);
        $validator = validator($request->all(), [
            'id' => ['required', 'string', 'max:2000'],
            'token' => ['required', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return back()
                ->withInput()
                ->with('edit_line', (string) $line->wa_instance)
                ->withErrors($validator);
        }
        $data = $validator->validated();
        $error = $this->pool->revise($line, $data['id'], $data['token']);
        if ($error !== null) {
            return back()
                ->withInput()
                ->with('edit_line', (string) $line->wa_instance)
                ->withErrors(['token' => __($error)]);
        }

        return redirect()
            ->route('ai-chatbot.farz.numbers', ['bot' => $instance->id])
            ->with('status', 'تم تحديث الرقم من Green API.');
    }

    public function warm(Request $request): RedirectResponse
    {
        $instance = $this->gate($request, true);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:40'],
            'lines.*' => ['string', 'max:32'],
            'phones' => ['required', 'string', 'max:4000'],
            'message' => ['required', 'string', 'max:1000'],
        ]);
        $result = $this->pool->warm($data['lines'], $data['phones'], $data['message']);
        if (is_string($result)) {
            return back()
                ->withInput()
                ->withErrors(['phones' => __($result)]);
        }

        $status = 'التسخين: نجح '.$result['sent'].'، فشل '.$result['failed'].'.';
        if (($result['skipped'] ?? 0) > 0) {
            $status .= ' تُرك الموقوف: '.$result['skipped'].'.';
        }

        return redirect()
            ->route('ai-chatbot.farz.numbers', ['bot' => $instance->id])
            ->with('status', $status);
    }

    /**
     * Move one number to a bot (0 = no bot). A number is on one bot at a time.
     */
    public function assign(Request $request, MowahadaLine $line): JsonResponse
    {
        $instance = $this->gate($request, true);
        $data = $request->validate([
            'target' => ['required', 'integer', 'min:0'],
        ]);

        $target = null;
        if ((int) $data['target'] > 0) {
            $target = $this->pool->mowahadaBots()->firstWhere('id', (int) $data['target']);
            abort_unless($target instanceof ChatbotInstance, 404);
            abort_unless($this->authz->can($request->user(), $target, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS), 403);
        }

        $id = (string) $line->wa_instance;
        $lines = app(MansourLines::class);
        $lines->assign($instance, [$id], $target, [$id => (string) $line->url]);
        if ($target !== null) {
            $this->pool->applyCatalogState($target, [$id]);
        }
        $owner = $this->pool->owners()[$id] ?? null;

        return response()->json([
            'ok' => true,
            'bot_id' => $owner?->id ?? 0,
            'message' => $owner === null ? 'الرقم غير مربوط بأي بوت' : 'الرقم الآن على '.$owner->name,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $this->gate($request, true);

        return response()->json($this->pool->live());
    }

    private function gate(Request $request, bool $manage): ChatbotInstance
    {
        $user = $request->user();
        $bots = $this->pool->mowahadaBots();
        abort_if($bots->isEmpty(), 404);

        $ability = $manage
            ? ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS
            : ChatbotAuthorizationService::ABILITY_VIEW;
        $allowed = $bots->filter(fn (ChatbotInstance $bot): bool => $this->authz->can($user, $bot, $ability))->values();
        abort_if($allowed->isEmpty(), 403);

        $requested = (int) $request->input('bot', $request->query('bot', 0));
        $instance = $allowed->firstWhere('id', $requested);

        return $instance instanceof ChatbotInstance ? $instance : $allowed->first();
    }
}
