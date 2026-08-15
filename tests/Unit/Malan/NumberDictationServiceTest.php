<?php

declare(strict_types=1);

namespace Tests\Unit\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Services\Malan\NumberDictationService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberDictationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NumberDictationService $service;

    private ChatbotInstance $instance;

    private ChatbotConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
        $this->service = app(NumberDictationService::class);

        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $this->instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
        ]);
        $this->conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $this->instance->id,
            'channel' => 'test',
        ]);
    }

    public function test_phone_chunks_ack_until_ten_digits(): void
    {
        $first = $this->service->ingest($this->conversation, $this->instance, 'صفر خمسة ثلاثة');
        $this->assertSame('incomplete', $first['status']);
        $this->assertSame('053', $first['digits']);
        $this->assertSame('phone', $first['kind']);
        $this->assertNotNull($first['reply']);

        $second = $this->service->ingest($this->conversation, $this->instance, 'صفر أربعة ستة');
        $this->assertSame('incomplete', $second['status']);
        $this->assertSame('053046', $second['digits']);

        $third = $this->service->ingest($this->conversation, $this->instance, 'ثمانية ثلاثة صفر واحد');
        $this->assertSame('complete', $third['status']);
        $this->assertSame('0530468301', $third['digits']);
        $this->assertSame('phone', $third['kind']);
        $this->assertNull($third['reply']);
    }

    public function test_identity_expects_nine_digits_when_not_starting_with_05(): void
    {
        $first = $this->service->ingest($this->conversation, $this->instance, '3 1 1');
        $this->assertSame('incomplete', $first['status']);
        $this->assertSame('311', $first['digits']);
        $this->assertSame('identity', $first['kind']);

        $done = $this->service->ingest($this->conversation, $this->instance, '987654');
        $this->assertSame('complete', $done['status']);
        $this->assertSame('311987654', $done['digits']);
        $this->assertSame('identity', $done['kind']);
    }

    public function test_reset_clears_buffer(): void
    {
        $this->service->ingest($this->conversation, $this->instance, '0530');
        $reset = $this->service->ingest($this->conversation, $this->instance, 'غلط من الأول');
        $this->assertSame('reset', $reset['status']);
        $this->assertStringContainsString('من أول', (string) $reset['reply']);

        $again = $this->service->ingest($this->conversation, $this->instance, '05');
        $this->assertSame('incomplete', $again['status']);
        $this->assertSame('05', $again['digits']);
    }

    public function test_repeated_number_words_are_kept(): void
    {
        $result = $this->service->ingest($this->conversation, $this->instance, 'صفر خمسة خمسة');
        $this->assertSame('incomplete', $result['status']);
        $this->assertSame('055', $result['digits']);
        $this->assertSame('phone', $result['kind']);
    }

    public function test_repeat_marker_duplicates_last_digit(): void
    {
        $result = $this->service->ingest($this->conversation, $this->instance, 'صفر خمسة مرتين');
        $this->assertSame('incomplete', $result['status']);
        $this->assertSame('055', $result['digits']);
    }

    public function test_repeated_digit_chars_are_kept(): void
    {
        $result = $this->service->ingest($this->conversation, $this->instance, '0 5 5 0');
        $this->assertSame('incomplete', $result['status']);
        $this->assertSame('0550', $result['digits']);
    }

    public function test_non_digit_chat_is_ignored(): void
    {
        $result = $this->service->ingest($this->conversation, $this->instance, 'النت عندي مقطوع من الصبح');
        $this->assertSame('none', $result['status']);
    }
}
