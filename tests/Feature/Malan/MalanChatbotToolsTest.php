<?php

declare(strict_types=1);

namespace Tests\Feature\Malan;

use App\Data\Malan\BankTransferProofVerificationResult;
use App\Data\Malan\MalanCustomerLookupResult;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotToolExecution;
use App\Models\Malan\MalanPaymentProof;
use App\Models\Malan\MalanSupportReport;
use App\Models\User;
use App\Services\AiChatbot\Tools\ChatbotToolDefinitions;
use App\Services\AiChatbot\Tools\ChatbotToolExecutor;
use App\Services\Malan\Contracts\BankTransferProofVerifier;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\Proof\MalanPaymentProofService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MalanChatbotToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);

        config([
            'services.openai.api_key' => 'test-openai',
            'malan.api.base_url' => 'https://www.malan.app',
            'malan.api.key' => 'test-malan-key',
            'malan.api.retries' => 0,
        ]);
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function malanInstance(User $user): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
            'system_prompt' => 'You are Sally for Malan.',
        ]);
    }

    public function test_tools_not_available_for_other_bots(): void
    {
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'integration_type' => null,
        ]);

        $tools = app(ChatbotToolDefinitions::class)->forInstance($instance);

        $this->assertSame([], $tools);
    }

    public function test_tenant_isolation_blocks_cross_instance_conversation(): void
    {
        $user = $this->user();
        $a = $this->malanInstance($user);
        $b = $this->malanInstance($user);

        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $a->id,
            'title' => 't',
        ]);

        $result = app(ChatbotToolExecutor::class)->execute(
            $b,
            $conversation,
            'lookup_malan_customer',
            ['lookup_type' => 'phone', 'value' => '0536079841', 'reason' => 'internet_outage'],
            'web',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Conversation/instance mismatch.', $result['message']);
    }

    public function test_cannot_create_support_report_before_verified_lookup(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'create_malan_support_report',
            [
                'issue_type' => 'full_outage',
                'summary' => 'outage',
                'customer_id' => 'FAKE-999',
                'confirmed_by_customer' => true,
            ],
            'web',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(0, MalanSupportReport::query()->count());
    }

    public function test_ai_cannot_pass_fake_customer_id_to_support_report(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
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
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => '*****3153',
                    'status' => 'ACTIVE',
                    'city' => null,
                ],
                financial: ['balance_raw' => 0.0, 'debt_amount' => null, 'currency' => 'ILS'],
            ),
        );

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'create_malan_support_report',
            [
                'issue_type' => 'full_outage',
                'summary' => 'outage',
                'customer_id' => 'FAKE-999',
                'confirmed_by_customer' => true,
            ],
            'web',
        );

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('malan_support_reports', [
            'external_customer_id' => '3119',
        ]);
        $this->assertDatabaseMissing('malan_support_reports', [
            'external_customer_id' => 'FAKE-999',
        ]);
    }

    public function test_support_report_requires_customer_confirmation(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
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
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'ACTIVE',
                    'city' => null,
                ],
                financial: ['balance_raw' => 0.0, 'debt_amount' => null, 'currency' => 'ILS'],
            ),
        );

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'create_malan_support_report',
            [
                'issue_type' => 'full_outage',
                'summary' => 'outage',
                'confirmed_by_customer' => false,
            ],
            'web',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('confirmation_required', $result['error_code'] ?? null);
        $this->assertSame(0, MalanSupportReport::query()->count());
    }

    public function test_duplicate_support_report_is_prevented(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
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
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'ACTIVE',
                    'city' => null,
                ],
                financial: ['balance_raw' => 0.0, 'debt_amount' => null, 'currency' => 'ILS'],
            ),
        );

        $executor = app(ChatbotToolExecutor::class);
        $first = $executor->execute($instance, $conversation, 'create_malan_support_report', [
            'issue_type' => 'full_outage',
            'summary' => 'one',
            'confirmed_by_customer' => true,
        ], 'web');
        $second = $executor->execute($instance, $conversation, 'create_malan_support_report', [
            'issue_type' => 'full_outage',
            'summary' => 'two',
            'confirmed_by_customer' => true,
        ], 'web');

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['duplicate'] ?? false);
        $this->assertSame(1, MalanSupportReport::query()->count());
    }

    public function test_visa_charge_returns_integration_pending(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
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
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'charge_malan_saved_payment_method',
            ['confirmed_by_customer' => true],
            'whatsapp',
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['integration_pending']);
    }

    public function test_tool_arguments_are_masked_in_executions_table(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([[
                'result' => true,
                'data' => [
                    'client' => [
                        'id' => '3119',
                        'client_name' => 'Test',
                        'client_phone' => '0536079841',
                        'client_identity' => '123456782',
                        'status' => 'ACTIVE',
                    ],
                    'financial_summary' => ['balance' => 0],
                ],
            ]], 200),
        ]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'lookup_malan_customer',
            ['lookup_type' => 'identity', 'value' => '123456782', 'reason' => 'internet_outage'],
            'web',
        );

        $execution = ChatbotToolExecution::query()->firstOrFail();
        $this->assertStringNotContainsString('123456782', json_encode($execution->arguments) ?: '');
        $this->assertStringContainsString('*', (string) ($execution->arguments['value'] ?? ''));
    }

    public function test_image_without_pending_bank_transfer_is_not_treated_as_proof(): void
    {
        Storage::fake('local');
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        $path = 'malan/payment-proofs/sample.jpg';
        Storage::disk('local')->put($path, 'fake-image');

        $result = app(MalanPaymentProofService::class)->handleIncomingProofFile(
            $instance,
            $conversation,
            $path,
            'image/jpeg',
            'msg-1',
        );

        $this->assertFalse($result['handled']);
        $this->assertFalse($result['awaiting_proof']);
        $this->assertSame(0, MalanPaymentProof::query()->count());
    }

    public function test_proof_with_wrong_amount_is_rejected_or_needs_review(): void
    {
        Storage::fake('local');
        $this->app->instance(BankTransferProofVerifier::class, new class implements BankTransferProofVerifier
        {
            public function verify(string $absoluteFilePath, array $expectations): BankTransferProofVerificationResult
            {
                return new BankTransferProofVerificationResult(
                    status: BankTransferProofVerificationResult::STATUS_REJECTED,
                    detectedAmount: 100.0,
                    detectedDate: $expectations['expected_date'],
                    referenceNumber: 'ABC',
                    amountMatch: false,
                    dateMatch: true,
                    referencePresent: true,
                    suspicionReasons: ['amount_mismatch_or_missing'],
                    confidence: 0.9,
                );
            }
        });

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        $context = app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );
        app(MalanConversationContextService::class)->setPaymentMethod(
            $conversation,
            $instance,
            'bank_transfer',
            'awaiting_bank_transfer_proof',
        );

        $path = 'malan/payment-proofs/wrong.jpg';
        Storage::disk('local')->put($path, 'fake');

        $result = app(MalanPaymentProofService::class)->handleIncomingProofFile(
            $instance,
            $conversation,
            $path,
            'image/jpeg',
            'msg-amount',
        );

        $this->assertTrue($result['handled']);
        $this->assertSame('rejected', $result['verification']->status);
        $this->assertDatabaseHas('malan_payment_proofs', [
            'verification_status' => 'rejected',
            'greenapi_message_id' => 'msg-amount',
        ]);
        unset($context);
    }

    public function test_duplicate_webhook_message_does_not_create_second_proof(): void
    {
        Storage::fake('local');
        $this->app->instance(BankTransferProofVerifier::class, new class implements BankTransferProofVerifier
        {
            public function verify(string $absoluteFilePath, array $expectations): BankTransferProofVerificationResult
            {
                return new BankTransferProofVerificationResult(
                    status: BankTransferProofVerificationResult::STATUS_NEEDS_REVIEW,
                    detectedAmount: 318.0,
                    detectedDate: $expectations['expected_date'],
                    referenceNumber: null,
                    amountMatch: true,
                    dateMatch: true,
                    referencePresent: false,
                    suspicionReasons: ['missing_reference_number'],
                    confidence: 0.5,
                );
            }
        });

        $user = $this->user();
        $instance = $this->malanInstance($user);
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
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );
        app(MalanConversationContextService::class)->setPaymentMethod(
            $conversation,
            $instance,
            'bank_transfer',
            'awaiting_bank_transfer_proof',
        );

        $path = 'malan/payment-proofs/dup.jpg';
        Storage::disk('local')->put($path, 'fake');
        $service = app(MalanPaymentProofService::class);

        $service->handleIncomingProofFile($instance, $conversation, $path, 'image/jpeg', 'msg-dup');
        $service->handleIncomingProofFile($instance, $conversation, $path, 'image/jpeg', 'msg-dup');

        $this->assertSame(1, MalanPaymentProof::query()->count());
    }
}
