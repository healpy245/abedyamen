<?php

declare(strict_types=1);

namespace Tests\Unit\AiChatbot;

use App\Services\AiChatbot\VoiceFastReplyComposer;
use PHPUnit\Framework\TestCase;

class VoiceFastReplyComposerTest extends TestCase
{
    public function test_not_found_uses_tool_message(): void
    {
        $text = (new VoiceFastReplyComposer)->fromToolCalls([[
            'name' => 'lookup_malan_customer',
            'result' => [
                'success' => false,
                'found' => false,
                'error_code' => 'not_found',
                'message' => 'هالرقم يبدو مش مسجّل بالنظام.',
            ],
        ]]);

        $this->assertSame('هالرقم يبدو مش مسجّل بالنظام.', $text);
    }

    public function test_debt_disconnected_composes_short_spoken_reply(): void
    {
        $text = (new VoiceFastReplyComposer)->fromToolCalls([[
            'name' => 'lookup_malan_customer',
            'result' => [
                'success' => true,
                'found' => true,
                'customer' => ['status' => 'DEBT_DISCONNECTED'],
                'financial' => ['debt_amount' => 318],
            ],
        ]]);

        $this->assertSame('حسابك مفصول عندي بسبب دين 318 شيكل. بتفضّل تسدّد تحويل بنكي ولا فيزا؟', $text);
    }

    public function test_active_composes_router_check(): void
    {
        $text = (new VoiceFastReplyComposer)->fromToolCalls([[
            'name' => 'lookup_malan_customer',
            'result' => [
                'success' => true,
                'found' => true,
                'customer' => ['status' => 'ACTIVE'],
            ],
        ]]);

        $this->assertStringContainsString('شغّال', (string) $text);
    }

    public function test_payment_preference_composes_short_spoken_reply(): void
    {
        $text = (new VoiceFastReplyComposer)->fromToolCalls([[
            'name' => 'set_malan_payment_method_preference',
            'result' => [
                'success' => true,
                'payment_method' => 'bank_transfer',
            ],
        ]]);

        $this->assertStringContainsString('تحويل بنكي', (string) $text);
    }

    public function test_unknown_tools_return_null(): void
    {
        $this->assertNull((new VoiceFastReplyComposer)->fromToolCalls([[
            'name' => 'some_other_tool',
            'result' => ['success' => true],
        ]]));
    }
}
