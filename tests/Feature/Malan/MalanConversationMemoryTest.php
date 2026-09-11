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

    public function test_new_signup_intent_sets_pending_flow_and_blocks_account_mixup(): void
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

        $memory = app(MalanConversationMemoryService::class);
        $updated = $memory->observeUserMessage($conversation, $instance, 'بدي نت');

        $this->assertSame('new_signup_intake', $updated?->pending_flow);

        $summary = app(MalanConversationContextService::class)->toPromptSummary($updated);
        $this->assertSame('new_signup', $summary['mode'] ?? null);
    }

    public function test_new_signup_after_verified_outage_clears_customer_and_blocks_lookup_bleed(): void
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
                    'id' => '1513',
                    'name' => 'Old',
                    'phone_masked' => '054***8848',
                    'identity_masked' => null,
                    'status' => 'ACTIVE',
                    'city' => null,
                ],
                financial: ['balance_raw' => 0.0, 'debt_amount' => null, 'currency' => 'ILS'],
            ),
        );

        $memory = app(MalanConversationMemoryService::class);
        $updated = $memory->observeUserMessage($conversation, $instance, 'بدي اركب انترنت بدار سيدي');

        $this->assertSame('new_signup_intake', $updated?->pending_flow);
        $this->assertFalse((bool) $updated?->hasVerifiedCustomer());
        $this->assertNull($updated?->verified_customer_id);

        $back = $memory->observeUserMessage($conversation, $instance, 'عندي مشكله بلانترنت');
        $this->assertFalse(app(MalanConversationContextService::class)->isNewSignupMode($back));
        $this->assertSame('internet_outage', $back?->pending_flow);
    }

    public function test_outage_phrase_mushkila_exits_new_signup_mode(): void
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

        $memory = app(MalanConversationMemoryService::class);
        $memory->observeUserMessage($conversation, $instance, 'بدي نت');
        $updated = $memory->observeUserMessage($conversation, $instance, 'عندي مشكله بلانترنت');

        $this->assertFalse(app(MalanConversationContextService::class)->isNewSignupMode($updated));
        $this->assertSame('internet_outage', $updated?->pending_flow);
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
