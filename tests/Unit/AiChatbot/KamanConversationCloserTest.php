<?php

declare(strict_types=1);

namespace Tests\Unit\AiChatbot;

use App\Services\AiChatbot\GreenApiIncomingMessage;
use App\Services\AiChatbot\KamanConversationCloser;
use Tests\TestCase;

class KamanConversationCloserTest extends TestCase
{
    public function test_thanks_phrases_close_and_questions_do_not(): void
    {
        $closer = new KamanConversationCloser;

        $this->assertTrue($closer->isThanksClosing('تسلمي'));
        $this->assertTrue($closer->isThanksClosing('شكرا'));
        $this->assertTrue($closer->isThanksClosing('تمام شكرا الك'));
        $this->assertTrue($closer->isThanksClosing('يعطيك العافية'));
        $this->assertTrue($closer->isThanksClosing('thank you'));
        $this->assertTrue($closer->isThanksClosing('תודה רבה'));
        $this->assertTrue($closer->isThanksClosing(
            GreenApiIncomingMessage::STICKER_LABEL."\nتسلمي"
        ));

        $this->assertFalse($closer->isThanksClosing(GreenApiIncomingMessage::STICKER_LABEL));
        $this->assertFalse($closer->isThanksClosing('شكرا، بدي السعر؟'));
        $this->assertFalse($closer->isThanksClosing('تمام'));
        $this->assertFalse($closer->isThanksClosing('يا هلا'));

        $this->assertSame('العفو', $closer->closingReplyFor('تسلمي'));
        $this->assertSame('בכיף', $closer->closingReplyFor('תודה'));
    }

    public function test_sticker_is_not_treated_as_a_photo(): void
    {
        $sticker = new GreenApiIncomingMessage(
            type: 'stickerMessage',
            chatId: '972500000000@c.us',
            messageId: 's1',
            text: null,
            caption: null,
            downloadUrl: 'https://example.com/smile.webp',
            mimeType: 'image/webp',
            fileName: 'smile.webp',
            quotedText: 'إذا حابب أشرح لك أكثر',
        );

        $this->assertTrue($sticker->isSticker());
        $this->assertFalse($sticker->isImage());
        $this->assertTrue($sticker->isMedia());
        $this->assertStringContainsString('ستيكر واتساب', (string) $sticker->customerFacingText());
        $this->assertStringContainsString('إذا حابب أشرح لك أكثر', (string) $sticker->customerFacingText());

        $photo = new GreenApiIncomingMessage(
            type: 'imageMessage',
            chatId: '972500000000@c.us',
            messageId: 'p1',
            text: null,
            caption: null,
            downloadUrl: 'https://example.com/menu.jpg',
            mimeType: 'image/jpeg',
            fileName: 'menu.jpg',
        );

        $this->assertFalse($photo->isSticker());
        $this->assertTrue($photo->isImage());
        $this->assertSame('[أرسل الزبون صورة/ملف عبر WhatsApp]', $photo->customerFacingText());
    }
}
