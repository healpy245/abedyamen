<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Normalized inbound Green API message (text or media).
 */
final class GreenApiIncomingMessage
{
    public const STICKER_LABEL = '[الزبون أرسل ستيكر واتساب]';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $type,
        public readonly string $chatId,
        public readonly ?string $messageId,
        public readonly ?string $text,
        public readonly ?string $caption,
        public readonly ?string $downloadUrl,
        public readonly ?string $mimeType,
        public readonly ?string $fileName,
        public readonly ?string $senderName = null,
        public readonly ?int $timestamp = null,
        public readonly array $raw = [],
        public readonly ?string $quotedText = null,
    ) {}

    public function isSticker(): bool
    {
        $type = strtolower($this->type);

        return in_array($type, ['stickermessage', 'stickermessagedata'], true);
    }

    public function isImage(): bool
    {
        if ($this->isSticker()) {
            return false;
        }

        $type = strtolower($this->type);
        if (in_array($type, ['imagemessage', 'imagemessagedata'], true)) {
            return true;
        }

        $mime = strtolower((string) $this->mimeType);

        return str_starts_with($mime, 'image/');
    }

    public function isPdf(): bool
    {
        return strtolower((string) $this->mimeType) === 'application/pdf'
            || str_ends_with(strtolower((string) $this->fileName), '.pdf');
    }

    public function isAudio(): bool
    {
        $type = strtolower($this->type);
        if (in_array($type, ['audiomessage', 'pttmessage', 'voicemessage'], true)) {
            return true;
        }

        $mime = strtolower((string) $this->mimeType);

        return str_starts_with($mime, 'audio/');
    }

    public function isMedia(): bool
    {
        return $this->isImage() || $this->isPdf() || $this->isAudio() || $this->isSticker();
    }

    public function customerFacingText(): ?string
    {
        if ($this->isSticker()) {
            return $this->stickerFacingText();
        }

        $text = trim((string) ($this->text ?? ''));
        if ($text !== '') {
            return $text;
        }

        $caption = trim((string) ($this->caption ?? ''));
        if ($caption !== '') {
            return $caption;
        }

        if ($this->isAudio()) {
            return '[رسالة صوتية عبر WhatsApp]';
        }

        if ($this->isImage() || $this->isPdf()) {
            return '[أرسل الزبون صورة/ملف عبر WhatsApp]';
        }

        return null;
    }

    private function stickerFacingText(): string
    {
        $bits = [];
        $text = trim((string) ($this->text ?? ''));
        $caption = trim((string) ($this->caption ?? ''));
        if ($text !== '') {
            $bits[] = $text;
        }
        if ($caption !== '' && $caption !== $text) {
            $bits[] = $caption;
        }
        $quoted = trim((string) ($this->quotedText ?? ''));
        if ($quoted !== '') {
            $bits[] = 'رداً على: "'.$quoted.'"';
        }

        if ($bits === []) {
            return self::STICKER_LABEL;
        }

        return self::STICKER_LABEL.' '.implode(' — ', $bits);
    }
}
