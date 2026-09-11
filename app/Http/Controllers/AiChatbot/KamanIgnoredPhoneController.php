<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Models\AiChatbot\ChatbotInstance;
use App\Services\AiChatbot\ChatbotGreenApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KamanIgnoredPhoneController extends Controller
{
    public function __construct(
        private readonly ChatbotGreenApiService $greenApi,
    ) {}

    /**
     * Add a phone number to the Kaman WhatsApp bot API ignore list
     * (shown separately from manually managed numbers in Settings).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $phone = trim($validated['phone']);
        $normalized = $this->greenApi->normalizePhoneDigits($phone);

        if ($normalized === '') {
            return response()->json([
                'message' => 'Invalid phone number.',
            ], 422);
        }

        $instance = ChatbotInstance::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->orderBy('id')
            ->first();

        if ($instance === null) {
            return response()->json([
                'message' => 'Kaman WhatsApp instance not found.',
            ], 404);
        }

        foreach ($instance->ignoredReplyPhones() as $ignored) {
            if ($this->greenApi->normalizePhoneDigits($ignored) === $normalized) {
                return response()->json([
                    'added' => false,
                    'already_ignored' => true,
                    'phone' => $phone,
                    'ignored_reply_phones' => $instance->manualIgnoredReplyPhones(),
                    'ignored_reply_phones_api' => $instance->apiIgnoredReplyPhones(),
                ]);
            }
        }

        $apiList = $instance->apiIgnoredReplyPhones();
        $apiList[] = $phone;

        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $settings['ignored_reply_phones_api'] = $apiList;
        $instance->forceFill(['integration_settings' => $settings])->save();

        return response()->json([
            'added' => true,
            'already_ignored' => false,
            'phone' => $phone,
            'ignored_reply_phones' => $instance->manualIgnoredReplyPhones(),
            'ignored_reply_phones_api' => $apiList,
        ], 201);
    }
}
