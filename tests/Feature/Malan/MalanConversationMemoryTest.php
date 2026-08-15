<?php

declare(strict_types=1);

namespace Tests\Feature\Malan;

use App\Data\Malan\MalanCustomerLookupResult;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\MalanConversationMemoryService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MalanConversationMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    public function test_user_bank_choice_is_stored_per_conversation_without_tool_call(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
        ]);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Test',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );

        $memory = app(MalanConversationMemoryService::class);
        $updated = $memory->observeUserMessage($conversation, $instance, 'بنكي');

        $this->assertSame('bank_transfer', $updated?->payment_method);
        $this->assertSame('awaiting_bank_transfer_proof', $updated?->pending_flow);

        $other = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);
        $this->assertNull($memory->rememberForConversation($other, $instance));
    }

    public function test_assistant_bank_proof_request_arms_awaiting_state(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
        ]);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Test',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );

        $assistantText = 'تمام. بنك הפועלים فرع 665 حساب 603495. ابعتلي صورة واضحة يظهر فيها المبلغ وמספר אסמכתה.';
        $updated = app(MalanConversationMemoryService::class)
            ->observeAssistantMessage($conversation, $instance, $assistantText);

        $this->assertSame('awaiting_bank_transfer_proof', $updated?->pending_flow);
        $this->assertSame('bank_transfer', $updated?->payment_method);
    }

    public function test_ensure_awaiting_repairs_missing_payment_method_from_history(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
        ]);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Test',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );

        $conversation->messages()->create([
            'role' => 'user',
            'message' => 'بنكي',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'message' => 'بيانات البنك: 665 / 603495. أرسل صورة واضحة مع מספר אסמכתה.',
        ]);

        $context = app(MalanConversationMemoryService::class)
            ->ensureAwaitingBankTransferProof($conversation, $instance);

        $this->assertSame('awaiting_bank_transfer_proof', $context?->pending_flow);
        $this->assertSame('bank_transfer', $context?->payment_method);
        $this->assertSame('3119', $context?->verified_customer_id);
    }
}
