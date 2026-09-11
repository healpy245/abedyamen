<?php

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KamanIgnoredPhoneApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_phone_to_kaman_api_ignore_list(): void
    {
        $this->makeKamanInstance();

        $response = $this->postJson('/api/kaman-whatsapp/ignored-phones/483275634', [
            'phone' => '0533123456',
        ]);

        $response->assertCreated()
            ->assertJson([
                'added' => true,
                'already_ignored' => false,
                'phone' => '0533123456',
                'ignored_reply_phones_api' => ['0533123456'],
            ]);

        $instance = ChatbotInstance::query()->first();
        $this->assertSame([], $instance->manualIgnoredReplyPhones());
        $this->assertContains('0533123456', $instance->apiIgnoredReplyPhones());
        $this->assertContains('0533123456', $instance->ignoredReplyPhones());
    }

    public function test_does_not_duplicate_against_manual_list(): void
    {
        $instance = $this->makeKamanInstance(['0533123456']);

        $response = $this->postJson('/api/kaman-whatsapp/ignored-phones/483275634', [
            'phone' => '+972533123456',
        ]);

        $response->assertOk()
            ->assertJson([
                'added' => false,
                'already_ignored' => true,
            ]);

        $instance->refresh();
        $this->assertCount(1, $instance->manualIgnoredReplyPhones());
        $this->assertSame([], $instance->apiIgnoredReplyPhones());
    }

    public function test_does_not_duplicate_equivalent_api_numbers(): void
    {
        $instance = $this->makeKamanInstance([], ['0533123456']);

        $response = $this->postJson('/api/kaman-whatsapp/ignored-phones/483275634', [
            'phone' => '+972533123456',
        ]);

        $response->assertOk()
            ->assertJson([
                'added' => false,
                'already_ignored' => true,
            ]);

        $instance->refresh();
        $this->assertCount(1, $instance->apiIgnoredReplyPhones());
    }

    public function test_rejects_missing_phone(): void
    {
        $this->makeKamanInstance();

        $this->postJson('/api/kaman-whatsapp/ignored-phones/483275634', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_rejects_wrong_token(): void
    {
        $this->makeKamanInstance();

        $this->postJson('/api/kaman-whatsapp/ignored-phones/wrong', [
            'phone' => '0533123456',
        ])->assertNotFound();
    }

    /**
     * @param  list<string>  $manual
     * @param  list<string>  $api
     */
    private function makeKamanInstance(array $manual = [], array $api = []): ChatbotInstance
    {
        $owner = User::factory()->create();

        return ChatbotInstance::query()->create([
            'user_id' => $owner->id,
            'name' => 'Kaman POS — WhatsApp',
            'system_prompt' => 'test',
            'stores_members' => false,
            'integration_type' => 'kaman_whatsapp',
            'integration_settings' => [
                'ignored_reply_phones' => $manual,
                'ignored_reply_phones_api' => $api,
            ],
        ]);
    }
}
