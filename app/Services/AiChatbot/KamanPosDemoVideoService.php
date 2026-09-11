<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Kaman POS demo clip: uploaded from workspace settings and sent on WhatsApp
 * when a customer asks how the cashier looks.
 */
class KamanPosDemoVideoService
{
    public const DISK = 'local';

    public const MAX_KILOBYTES = 25600;

    public const CAPTION = 'هيك تقريبا بيجي شكل الكوباه عنا. بنركبها على أي جهاز عندك، ما بنجبرك تشتري أجهزة منا.';

    /**
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = ['mp4', 'mov', 'webm', '3gp', 'm4v'];

    public function customerAskedForVisual(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        return (bool) preg_match(
            '/صور[ةه]|فيديو|نموذج|screenshot|\bphoto\b|\bpicture\b|\bvideo\b|תמונה|וידאו|איך זה נראה|شكل\s*(ال)?(كوباه|كوبا|كوبوت|نظام|معريخت)|كيف\s*شكل|ور[يی]ني/iu',
            $text
        );
    }

    public function isReady(ChatbotInstance $instance): bool
    {
        $demo = $this->settings($instance);
        $path = (string) ($demo['path'] ?? '');
        if ($path === '') {
            return false;
        }

        return Storage::disk((string) ($demo['disk'] ?? self::DISK))->exists($path);
    }

    public function caption(): string
    {
        return self::CAPTION;
    }

    public function fileName(ChatbotInstance $instance): string
    {
        $demo = $this->settings($instance);
        $name = trim((string) ($demo['file_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $original = trim((string) ($demo['original_name'] ?? ''));
        if ($original !== '') {
            return $original;
        }

        return 'kaman-pos.mp4';
    }

    public function mime(ChatbotInstance $instance): string
    {
        $demo = $this->settings($instance);
        $mime = trim((string) ($demo['mime'] ?? ''));

        return $mime !== '' ? $mime : 'video/mp4';
    }

    public function publicUrl(ChatbotInstance $instance): ?string
    {
        $token = trim((string) ($this->settings($instance)['token'] ?? ''));
        if ($token === '' || ! $this->isReady($instance)) {
            return null;
        }

        return route('ai-chatbot.public.pos-demo', ['token' => $token], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function store(ChatbotInstance $instance, UploadedFile $file): array
    {
        $this->deleteStoredFile($instance);

        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'mp4'));
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            $ext = 'mp4';
        }

        $dir = 'kaman-pos-demo/'.$instance->id;
        $path = $dir.'/demo.'.$ext;
        Storage::disk(self::DISK)->putFileAs($dir, $file, 'demo.'.$ext);

        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $settings['pos_demo'] = [
            'disk' => self::DISK,
            'path' => $path,
            'token' => Str::random(48),
            'original_name' => (string) $file->getClientOriginalName(),
            'file_name' => 'kaman-pos.'.$ext,
            'mime' => (string) ($file->getMimeType() ?: 'video/mp4'),
            'uploaded_at' => now()->toIso8601String(),
        ];
        $instance->forceFill(['integration_settings' => $settings])->save();

        return $settings['pos_demo'];
    }

    public function destroy(ChatbotInstance $instance): void
    {
        $this->deleteStoredFile($instance);
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        unset($settings['pos_demo']);
        $instance->forceFill(['integration_settings' => $settings])->save();
    }

    public function streamByToken(string $token): StreamedResponse
    {
        $token = trim($token);
        if ($token === '') {
            throw new NotFoundHttpException;
        }

        $instance = ChatbotInstance::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->get()
            ->first(function (ChatbotInstance $row) use ($token): bool {
                return hash_equals((string) ($this->settings($row)['token'] ?? ''), $token);
            });

        if ($instance === null || ! $this->isReady($instance)) {
            throw new NotFoundHttpException;
        }

        $demo = $this->settings($instance);
        $disk = (string) ($demo['disk'] ?? self::DISK);
        $path = (string) $demo['path'];

        return Storage::disk($disk)->response($path, $this->fileName($instance), [
            'Content-Type' => $this->mime($instance),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function createOutboundMessage(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        int $triggerUserMessageId,
        int $version,
        string $chatId,
    ): ChatbotMessage {
        $demo = $this->settings($instance);

        return $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'video',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $this->caption(),
            'delivery_status' => 'pending',
            'attachment_disk' => (string) ($demo['disk'] ?? self::DISK),
            'attachment_path' => (string) ($demo['path'] ?? ''),
            'attachment_mime' => $this->mime($instance),
            'metadata' => [
                'kaman_pos_demo' => true,
                'kaman_delivery' => 'queued',
                'kaman_response_state' => KamanConversationBurstService::STATE_PENDING_SEND,
                'kaman_generated_for_version' => $version,
                'trigger_user_message_id' => $triggerUserMessageId,
                'greenapi_chat_id' => $chatId,
                'pos_demo_file_name' => $this->fileName($instance),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(ChatbotInstance $instance): array
    {
        $all = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $demo = $all['pos_demo'] ?? [];

        return is_array($demo) ? $demo : [];
    }

    private function deleteStoredFile(ChatbotInstance $instance): void
    {
        $demo = $this->settings($instance);
        $path = (string) ($demo['path'] ?? '');
        if ($path === '') {
            return;
        }

        $disk = (string) ($demo['disk'] ?? self::DISK);
        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
