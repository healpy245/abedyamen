<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\MansourSendRun;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\MansourBotService;
use App\Services\AiChatbot\MansourLines;
use App\Services\AiChatbot\MansourPrompt;
use App\Services\AiChatbot\MowahadaLinePool;
use App\Services\AiChatbot\WhapiCloudClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MansourBotController extends Controller
{
    public function __construct(
        protected ChatbotAuthorizationService $authz,
        protected MansourBotService $bots,
    ) {}

    public function updateSettings(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        $this->assertMansour($instance);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'greenapi_url' => ['nullable', 'string', 'max:2000'],
            'greenapi_urls' => ['nullable', 'array', 'max:80'],
            'greenapi_urls.*' => ['nullable', 'string', 'max:2000'],
            'greenapi_active' => ['nullable', 'array'],
            'greenapi_active.*' => ['string', 'max:64'],
            'greenapi_active_present' => ['nullable', 'string', 'max:8'],
            'system_prompt' => ['required', 'string', 'max:20000'],
            'opening_message' => ['required', 'string', 'max:2000'],
            'whatsapp_provider' => ['sometimes', 'in:greenapi,whapi'],
            'whapi_token' => ['nullable', 'string', 'max:2000'],
            'whapi_webhook_url' => ['nullable', 'string', 'max:2000'],
            'greenapi_webhook_url' => ['nullable', 'string', 'max:2000'],
        ], [
            'greenapi_urls.max' => __('chatbot.mansour.line_too_many'),
        ]);

        $whapi = app(WhapiCloudClient::class);
        $whapiError = $whapi->consumeSettingsRequest($validated);
        if ($whapiError !== null) {
            return back()
                ->withInput()
                ->withErrors([str_contains($whapiError, 'webhook') ? 'whapi_webhook_url' : 'whapi_token' => __($whapiError)]);
        }

        $webhookError = app(\App\Services\AiChatbot\ChatbotGreenApiService::class)
            ->assignWebhookToken($instance, $validated['greenapi_webhook_url'] ?? null);
        if ($webhookError !== null) {
            return back()
                ->withInput()
                ->withErrors(['greenapi_webhook_url' => __($webhookError)]);
        }

        $pool = app(MowahadaLinePool::class);
        if ($request->boolean('lines_keep') && $pool->appliesTo($instance)) {
            // Numbers are linked and switched on the spot from the lines panel; this form only saves the bot.
            $this->bots->saveSettings(
                $instance,
                $validated['name'],
                null,
                $validated['system_prompt'],
                $validated['opening_message'],
            );

            return redirect()
                ->route('ai-chatbot.workspace.settings', $instance)
                ->with('status', __('chatbot.mansour.saved'));
        }
        if ($request->boolean('pool_present') && $pool->appliesTo($instance)) {
            $before = array_column(app(MansourLines::class)->lines($instance), 'id');
            $ids = array_values(array_filter(array_map(
                static fn ($id): string => trim((string) $id),
                (array) $request->input('pool_ids', []),
            )));
            $urls = $pool->urlsFor($instance, $ids);
            $pool->remember($urls);
            $this->bots->saveSettings(
                $instance,
                $validated['name'],
                $urls,
                $validated['system_prompt'],
                $validated['opening_message'],
                null,
                null,
                array_map('strval', (array) $request->input('greenapi_active', [])),
            );
            $pool->applyCatalogState($instance, array_values(array_diff($ids, $before)));
        } else {
            $raw = array_key_exists('greenapi_urls', $validated)
                ? array_map('strval', (array) $validated['greenapi_urls'])
                : [(string) ($validated['greenapi_url'] ?? '')];
            [$urls, $bad] = $this->lineUrls($raw);
            if ($bad !== []) {
                return back()
                    ->withInput()
                    ->withErrors(['greenapi_urls' => __('chatbot.mansour.line_url_invalid')]);
            }

            $this->bots->saveSettings(
                $instance,
                $validated['name'],
                $urls,
                $validated['system_prompt'],
                $validated['opening_message'],
                null,
                null,
                $request->exists('greenapi_active_present')
                    ? array_map('strval', (array) $request->input('greenapi_active', []))
                    : null,
            );
            if ($pool->appliesTo($instance)) {
                $pool->remember($urls);
            }
        }

        $ids = [];
        foreach ($urls as $url) {
            $ids[app(MansourLines::class)->idFor($url)] = true;
        }
        $status = __('chatbot.mansour.saved_lines', ['count' => count($ids)]);
        if (count($ids) < count($urls)) {
            $status .= ' '.__('chatbot.mansour.line_duplicate');
        }
        if ($whapi->webhookWarning) {
            $status .= ' '.__('chatbot.whapi_webhook_failed');
        }

        return redirect()
            ->route('ai-chatbot.workspace.settings', $instance)
            ->with('status', $status);
    }

    public function lineStates(Request $request, ChatbotInstance $instance, MansourLines $lines): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->assertMansour($instance);
        $lines->refreshStatesIfDue($instance);
        $instance->refresh();

        return response()->json([
            'lines' => array_map(function (array $line) use ($lines): array {
                $blocked = $line['status'] === MansourLines::BLOCKED;
                $reason = (string) ($line['reason'] ?? '');
                $on = $lines->isOn($line);

                return [
                    'id' => $line['id'],
                    'status' => $line['status'],
                    'reason' => $reason,
                    'on' => $on,
                    'phone' => preg_replace('/\D+/', '', (string) ($line['phone'] ?? '')) ?? '',
                    'label' => $blocked
                        ? (MansourLines::ALERT_LABELS[$reason] ?? ($reason === 'quota' ? 'انتهى الرصيد' : 'موقوف'))
                        : ($on ? 'يعمل' : 'متوقف'),
                ];
            }, $lines->lines($instance)),
        ]);
    }

    /**
     * "تحديث": re-read each line from Green API (state, phone, name, photo) and point its webhook back here.
     */
    public function checkLines(Request $request, ChatbotInstance $instance, MansourLines $lines): JsonResponse|RedirectResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        $this->assertMansour($instance);
        @set_time_limit(90);

        $pool = app(MowahadaLinePool::class);
        if ($pool->appliesTo($instance)) {
            $pool->refresh();
        }
        $result = $lines->checkAll($instance);
        $message = 'تم فحص '.$result['checked'].' خط من Green API'
            .($result['blocked'] > 0 ? '، منها '.$result['blocked'].' موقوف' : '')
            .'. إذا تغيّر رقم واتساب يظهر الرقم الجديد.';

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message] + $result);
        }

        return redirect()
            ->route('ai-chatbot.workspace.settings', $instance)
            ->with('status', $message);
    }

    /**
     * Put numbers on one bot of the same owner (taking them off any other), or on none.
     */
    public function assignLines(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        $this->assertMansour($instance);

        $validated = $request->validate([
            'line_ids' => ['required', 'array', 'min:1', 'max:80'],
            'line_ids.*' => ['string', 'max:64'],
            'bot_id' => ['required', 'integer', 'min:0'],
        ]);

        $lines = app(MansourLines::class);
        $target = null;
        if ((int) $validated['bot_id'] > 0) {
            $target = (int) $validated['bot_id'] === (int) $instance->id
                ? $instance
                : $lines->siblings($instance)->firstWhere('id', (int) $validated['bot_id']);
            abort_unless($target instanceof ChatbotInstance, 404);
            $this->authz->authorize($request->user(), $target, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        }

        $pool = app(MowahadaLinePool::class);
        $catalog = $pool->appliesTo($instance) ? $pool->catalogUrls($validated['line_ids']) : [];
        $moved = $lines->assign($instance, $validated['line_ids'], $target, $catalog);
        if ($target !== null && $catalog !== []) {
            $pool->applyCatalogState($target, $moved);
        }

        return response()->json([
            'ok' => $moved !== [],
            'ids' => $moved,
            'message' => $moved === []
                ? 'لم يتغيّر شيء'
                : ($target === null
                    ? 'تم فصل '.count($moved).' رقم'
                    : 'تم ربط '.count($moved).' رقم بـ '.$target->name),
        ]);
    }

    public function setLinesActive(Request $request, ChatbotInstance $instance): JsonResponse|RedirectResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        $this->assertMansour($instance);

        $validated = $request->validate([
            'line_ids' => ['required', 'array', 'min:1', 'max:50'],
            'line_ids.*' => ['string', 'max:64'],
            'active' => ['required', 'boolean'],
        ]);

        $on = $request->boolean('active');
        $lines = app(MansourLines::class);
        $pool = app(MowahadaLinePool::class);
        if ($on && $pool->appliesTo($instance)) {
            $current = $lines->lines($instance);
            $have = array_column($current, 'id');
            $missing = array_values(array_diff($validated['line_ids'], $have));
            $extra = $missing === [] ? [] : $pool->urlsFor($instance, $missing);
            if ($extra !== []) {
                $lines->attach($instance, $extra);
                $pool->applyCatalogState($instance->fresh() ?? $instance, $missing);
                $instance->refresh();
            }
        }
        $ids = $lines->setManyActive($instance, $validated['line_ids'], $on);
        $message = $ids === []
            ? 'ما في خط جاهز للتشغيل'
            : ($on ? 'تم تشغيل '.count($ids) : 'تم إيقاف '.count($ids));

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $ids !== [],
                'active' => $on,
                'ids' => $ids,
                'message' => $message,
            ]);
        }

        return back()->with('status', $message);
    }

    public function setLineActive(Request $request, ChatbotInstance $instance, string $line): RedirectResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertMansour($instance);

        $on = $request->boolean('active');
        app(MansourLines::class)->setActive($instance, $line, $on);

        return back()->with('status', $on ? 'هذا الرقم يعمل' : 'هذا الرقم موقف');
    }

    public function contacts(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->assertMansour($instance);

        $lines = app(MansourLines::class);
        $choices = $lines->choices($instance);
        $primaryId = $lines->primaryId($instance);
        $rows = $instance->mansourContacts()
            ->with('conversation:id,metadata')
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'triggered_at', 'triggered_lines', 'conversation_id'])
            ->map(function ($contact) use ($choices, $primaryId) {
                $sentLines = [];
                foreach ($choices as $choice) {
                    if ($contact->sentOnLine($choice['id'], $primaryId)) {
                        $sentLines[] = $choice['id'];
                    }
                }

                return [
                    'id' => (int) $contact->id,
                    'name' => (string) ($contact->name ?? ''),
                    'phone' => (string) $contact->phone,
                    'sent_lines' => $sentLines,
                    'triggered' => $contact->triggered_at !== null || $sentLines !== [],
                ];
            });

        return response()->json([
            'contacts' => $rows,
            'lines' => array_map(static fn (array $line): array => [
                'id' => $line['id'],
                'label' => $line['label'],
                'phone' => $line['phone'],
                'active' => (bool) ($line['active'] ?? false),
                'status' => (string) ($line['status'] ?? ''),
                'today' => (int) ($line['today'] ?? 0),
                'cap' => (int) ($line['cap'] ?? MansourLines::DAY_CAP),
                'level' => (string) ($line['level'] ?? 'low'),
            ], $choices),
        ]);
    }

    public function followups(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->assertMansour($instance);
        abort_unless($instance->isAppsBot(), 404);

        return response()->json([
            'contacts' => $this->bots->followUps($instance),
        ]);
    }

    public function storeContacts(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        $this->assertMansour($instance);

        $request->validate([
            'excel' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120'],
        ]);

        $added = $this->bots->replaceContacts($instance, $request->file('excel'));

        return back()->with('status', __('chatbot.mansour.list_added', ['count' => $added]));
    }

    public function storeCustomContact(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertMansour($instance);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($request->boolean('followup')) {
            abort_unless($instance->isAppsBot(), 404);
            $queued = $this->bots->queueFollowUp($instance, $validated['phone'], $validated['name'] ?? null);
            if (! ($queued['ok'] ?? false)) {
                $code = (string) ($queued['code'] ?? 'bad_phone');

                return response()->json([
                    'ok' => false,
                    'message' => __('chatbot.mansour.'.$code),
                ], 422);
            }

            return response()->json([
                'ok' => true,
                'contact' => [
                    'id' => (int) $queued['id'],
                ],
            ]);
        }

        $contact = $this->bots->addContact($instance, $validated['phone'], $validated['name'] ?? null);
        if ($contact === null) {
            return response()->json([
                'ok' => false,
                'message' => __('chatbot.mansour.bad_phone'),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'contact' => [
                'id' => (int) $contact->id,
                'name' => (string) ($contact->name ?? ''),
                'phone' => (string) $contact->phone,
                'triggered' => $contact->triggered_at !== null,
            ],
        ]);
    }

    public function destroyContacts(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertMansour($instance);

        $ids = array_map('intval', (array) $request->input('contact_ids', []));
        $deleted = $this->bots->deleteContacts($instance, $ids);

        return response()->json([
            'ok' => true,
            'deleted' => $deleted,
        ]);
    }

    public function trigger(Request $request, ChatbotInstance $instance): RedirectResponse|JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertMansour($instance);

        if ($request->header('X-Mansour-Live') === '1') {
            return $this->triggerLive($request, $instance);
        }

        $ids = array_map('intval', (array) $request->input('contact_ids', []));
        $line = $request->input('line');
        $kind = $this->triggerKind($request, $instance);

        try {
            $result = $this->bots->trigger($instance, $ids, null, is_string($line) ? $line : null, $kind);
        } catch (RuntimeException $e) {
            return back()->withErrors(['trigger' => $this->triggerError($e)]);
        }

        $status = __('chatbot.mansour.sent', ['count' => $result['sent']]);
        if (($result['failed'] ?? 0) > 0) {
            $status .= ' '.__('chatbot.mansour.not_delivered', ['count' => $result['failed']]);
        }

        return back()->with('status', $status);
    }

    public function sends(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertMansour($instance);

        $runs = MansourSendRun::query()
            ->where('instance_id', $instance->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (MansourSendRun $run): array => [
                'id' => (int) $run->id,
                'sent' => (int) $run->sent,
                'failed' => (int) $run->failed,
                'note' => $run->note,
                'finished' => $run->finished_at !== null,
                'at' => $run->created_at?->toIso8601String(),
                'results' => array_values($run->results ?? []),
            ])
            ->all();

        return response()->json(['runs' => $runs]);
    }

    private function triggerLive(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $ids = array_map('intval', (array) $request->input('contact_ids', []));
        $runId = (int) $request->input('run_id', 0);
        $run = $runId > 0
            ? MansourSendRun::query()->where('instance_id', $instance->id)->whereKey($runId)->first()
            : null;

        if ($instance->outboundHeld()) {
            $run = $this->storeSendRun($instance, $run, [], __('chatbot.mansour.debug_held'), true);

            return response()->json([
                'ok' => false,
                'code' => 'held',
                'message' => __('chatbot.mansour.debug_held'),
                'run_id' => $run->id,
                'rows' => [],
            ]);
        }

        if ($ids === [] && $run instanceof MansourSendRun && $request->boolean('finish')) {
            if ($run->finished_at === null) {
                $run->forceFill(['finished_at' => now()])->save();
                $this->pruneSendRuns((int) $instance->id);
            }

            return response()->json([
                'ok' => true,
                'run_id' => $run->id,
                'rows' => [],
                'sent' => 0,
                'failed' => 0,
            ]);
        }

        $line = $request->input('line');
        $kind = $this->triggerKind($request, $instance);

        try {
            $rows = [];
            $result = $this->bots->trigger($instance, $ids, function (array $row) use (&$rows): void {
                $rows[] = $row;
            }, is_string($line) ? $line : null, $kind);
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $this->triggerError($e),
            ], 422);
        }

        if ($rows !== []) {
            $run = $this->storeSendRun($instance, $run, $rows, null, $request->boolean('finish'));
        }

        return response()->json([
            'ok' => true,
            'run_id' => $run?->id,
            'rows' => $rows,
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeSendRun(ChatbotInstance $instance, ?MansourSendRun $run, array $rows, ?string $note, bool $finish): MansourSendRun
    {
        if ($run === null) {
            $run = MansourSendRun::query()->create([
                'instance_id' => $instance->id,
                'sent' => 0,
                'failed' => 0,
                'results' => [],
            ]);
        }

        $results = array_values($run->results ?? []);
        $sent = (int) $run->sent;
        $failed = (int) $run->failed;
        foreach ($rows as $row) {
            $results[] = $row;
            if (($row['ok'] ?? false) === true) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $run->forceFill([
            'sent' => $sent,
            'failed' => $failed,
            'note' => $note ?? $run->note,
            'results' => $results,
            'finished_at' => $finish ? now() : null,
        ])->save();

        if ($finish) {
            $this->pruneSendRuns((int) $instance->id);
        }

        return $run;
    }

    private function pruneSendRuns(int $instanceId): void
    {
        $stale = MansourSendRun::query()
            ->where('instance_id', $instanceId)
            ->orderByDesc('id')
            ->skip(25)
            ->limit(100)
            ->pluck('id');
        if ($stale->isNotEmpty()) {
            MansourSendRun::query()->whereIn('id', $stale->all())->delete();
        }
    }

    /**
     * @param  list<string>  $raw
     * @return array{0: list<string>, 1: list<string>}
     */
    private function lineUrls(array $raw): array
    {
        return app(MowahadaLinePool::class)->extract($raw);
    }

    private function triggerError(RuntimeException $e): string
    {
        $key = match ($e->getMessage()) {
            'none_selected' => 'chatbot.mansour.need_one',
            'missing_opening' => 'chatbot.mansour.need_opening',
            'line_off' => 'chatbot.mansour.line_off',
            'dima_outbound_off' => 'chatbot.mansour.dima_outbound_off',
            default => 'chatbot.mansour.need_setup',
        };

        return __($key);
    }

    public function copy(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        $this->assertMansour($instance);

        $copy = $this->bots->copy($instance, $request->user());

        return redirect()
            ->route('ai-chatbot.index', ['list' => 1])
            ->with('status', __('chatbot.mansour.copied'));
    }

    private function triggerKind(Request $request, ChatbotInstance $instance): string
    {
        if ($request->input('kind') !== 'followup') {
            return 'opening';
        }
        abort_unless($instance->isAppsBot(), 404);

        return 'followup';
    }

    public function opening(ChatbotInstance $instance): string
    {
        return MansourPrompt::opening($instance);
    }

    private function assertMansour(ChatbotInstance $instance): void
    {
        abort_unless($instance->hasMansourIntegration(), 404);
    }
}
