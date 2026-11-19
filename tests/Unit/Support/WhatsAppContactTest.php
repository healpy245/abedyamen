<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\WhatsAppContact;
use PHPUnit\Framework\TestCase;

class WhatsAppContactTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function texts(): array
    {
        return [
            'plain local mobile' => ['the printer is stuck, call me 0501234567', '972501234567'],
            'dashes' => ['טלפון: 052-123-4567 תודה', '972521234567'],
            'spaces' => ['رقمي 054 765 4321', '972547654321'],
            'international' => ['call +972 50 123 4567', '972501234567'],
            'trunk zero after code' => ['+972-050-1234567', '972501234567'],
            'double zero' => ['00972501234567', '972501234567'],
            'palestinian 059' => ['جوال 0599123456', '970599123456'],
            'palestinian with code' => ['+970 59 912 3456', '970599123456'],
            'arabic digits' => ['رقمي ٠٥٠١٢٣٤٥٦٧', '972501234567'],
            'two numbers keeps first' => ['0501234567 0547654321', '972501234567'],
            'other country' => ['+44 7700 900123', '447700900123'],
            'landline is not whatsapp' => ['call the shop 04-6123456', null],
            'date and time' => ['since 12.11.2026 14:30 it fails', null],
            'order number' => ['order 123456789 did not print', null],
            'price' => ['total 1,250.00', null],
            'nothing' => ['the screen is frozen', null],
        ];
    }

    /**
     * @dataProvider texts
     */
    public function test_finds_the_mobile_number(string $text, ?string $digits): void
    {
        $found = WhatsAppContact::find($text);

        if ($digits === null) {
            $this->assertNull($found);

            return;
        }
        $this->assertNotNull($found);
        $this->assertSame('https://wa.me/'.$digits, $found['whatsapp']);
    }

    public function test_shows_local_numbers_the_local_way(): void
    {
        $this->assertSame('050-123-4567', WhatsAppContact::find('+972501234567')['phone'] ?? null);
        $this->assertSame('+447700900123', WhatsAppContact::find('+44 7700 900123')['phone'] ?? null);
    }

    public function test_reads_the_details_before_the_summary(): void
    {
        $this->assertSame('https://wa.me/972521111111', WhatsAppContact::find('call 0521111111', 'or 0532222222')['whatsapp'] ?? null);
        $this->assertSame('https://wa.me/972532222222', WhatsAppContact::find(null, 'or 0532222222')['whatsapp'] ?? null);
    }
}
