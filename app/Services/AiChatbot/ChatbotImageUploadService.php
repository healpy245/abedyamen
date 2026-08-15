<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Services\Malan\Proof\MalanPaymentProofService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ChatbotImageUploadService
{
    public function __construct(
        protected AiChatbotService $chatbotService,
        protected MalanPaymentProofService $paymentProofService,
    ) {
    }

    /**
     * @return array{conversation:ChatbotConversation,user_message:ChatbotMessage,assistant_message:ChatbotMessage}
     */
    public function handle(
        User $user,
        ChatbotInstance $instance,
        UploadedFile $file,
        ?int $conversationId = null,
        ?string $caption = null,
    ): array {
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $allowed = config('malan.media.allowed_mimes', []);
        if (! in_array($mime, $allowed, true)) {
            throw new RuntimeException('نوع الملف غير مدعوم. ارفع صورة JPEG/PNG/WebP أو PDF.');
        }

        $maxBytes = (int) config('malan.media.max_bytes', 5 * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException('حجم الملف أكبر من المسموح.');
        }

        $disk = (string) config('malan.media.disk', 'local');
        $directory = trim((string) config('malan.media.directory', 'malan/payment-proofs'), '/');
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => $file->getClientOriginalExtension() ?: 'bin',
        };

        $relativePath = $directory.'/'.Str::uuid()->toString().'.'.$extension;
        Storage::disk($disk)->put($relativePath, file_get_contents($file->getRealPath()) ?: '');

        $conversation = $this->chatbotService->resolveConversation($user, $instance, $conversationId);

        $captionText = trim((string) $caption);
        $userText = $captionText !== ''
            ? $captionText
            : ($mime === 'application/pdf' ? '[ملف PDF مرفق]' : '[صورة مرفقة]');

        if ($conversation->title === null) {
            $conversation->title = mb_substr(trim(preg_replace('/\s+/', ' ', $userText) ?? $userText), 0, 60) ?: 'New chat';
            $conversation->save();
        }

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'message' => $userText,
            'attachment_disk' => $disk,
            'attachment_path' => $relativePath,
            'attachment_mime' => $mime,
        ]);

        if ($instance->hasMalanIntegration()) {
            $proofResult = $this->paymentProofService->handleIncomingProofFile(
                $instance,
                $conversation,
                $relativePath,
                $mime,
                'web-upload-'.$userMessage->id,
            );

            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'message' => $proofResult['customer_message'],
            ]);

            return [
                'conversation' => $conversation->fresh(),
                'user_message' => $userMessage->fresh(),
                'assistant_message' => $assistantMessage,
            ];
        }

        // Non-Malan bots: ask the model what the attachment is about.
        $ai = $this->chatbotService->generateAssistantReplyForConversation(
            $conversation,
            $instance,
            'web',
            false,
            "[ملاحظة نظام: أرفق الزبون ملفًا من نوع {$mime}. اسأليه عن المقصود إن لم يكن واضحًا من السياق.]",
        );

        return [
            'conversation' => $ai['conversation'],
            'user_message' => $userMessage->fresh(),
            'assistant_message' => $ai['assistant_message'],
        ];
    }
}
