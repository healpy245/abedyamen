<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Data\Malan\BankTransferProofVerificationResult;
use App\Data\Malan\MalanCustomerLookupResult;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanPaymentProof;
use App\Models\User;
use App\Services\Malan\Contracts\BankTransferProofVerifier;
use App\Services\Malan\MalanConversationContextService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatbotImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
        Storage::fake('local');
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function fakeJpeg(string $name = 'receipt.jpg'): UploadedFile
    {
        // Minimal valid-enough JPEG bytes without requiring GD.
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBAQEBAVFRUVFRUVFRUVFRUWFxUVFRUYHSggGBolGxUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAADBAECBQYAB//EADkQAAIBAgQDBgUEAwEBAAAAAAECAwQRAAUSITFBBhMiUWEycYGRobHB0fAVQlLhFjNikqLx/8QAGQEAAwEBAQAAAAAAAAAAAAAAAAECAwQF/8QAIxEAAgICAgMAAwEAAAAAAAAAAAECEQMhEjFBBQYiQRNh/9oADAMBAAIRAxEAPwD1SgAoAKACgD//2Q=='
        );

        return UploadedFile::fake()->createWithContent($name, $bytes ?: 'fake-jpeg');
    }

    public function test_web_chat_can_upload_image_outside_bank_transfer_flow(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
        ]);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'title' => 'upload test',
        ]);

        $file = $this->fakeJpeg('receipt.jpg');

        $response = $this->actingAs($user)->post(
            route('ai-chatbot.instances.upload-image', $instance),
            [
                'image' => $file,
                'conversation_id' => $conversation->id,
                'caption' => 'صورة التحويل',
            ],
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'conversation' => ['id'],
            'user_message_html',
            'assistant_message_html',
        ]);
        $this->assertStringContainsString('img', $response->json('user_message_html'));
        $this->assertSame(0, MalanPaymentProof::query()->count());
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'message' => 'صورة التحويل',
        ]);
    }

    public function test_web_chat_upload_processes_bank_transfer_proof_when_awaiting(): void
    {
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
                    confidence: 0.6,
                );
            }
        });

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
        app(MalanConversationContextService::class)->setPaymentMethod(
            $conversation,
            $instance,
            'bank_transfer',
            'awaiting_bank_transfer_proof',
        );

        $file = $this->fakeJpeg('proof.jpg');

        $this->actingAs($user)->post(
            route('ai-chatbot.instances.upload-image', $instance),
            [
                'image' => $file,
                'conversation_id' => $conversation->id,
            ],
        )->assertOk();

        $this->assertSame(1, MalanPaymentProof::query()->count());
        $this->assertDatabaseHas('malan_payment_proofs', [
            'conversation_id' => $conversation->id,
            'verification_status' => 'needs_review',
        ]);
    }

    public function test_attachment_route_is_tenant_scoped(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create(['user_id' => $user->id]);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        Storage::disk('local')->put('malan/payment-proofs/x.jpg', 'fake');
        $message = $conversation->messages()->create([
            'role' => 'user',
            'message' => '[صورة]',
            'attachment_disk' => 'local',
            'attachment_path' => 'malan/payment-proofs/x.jpg',
            'attachment_mime' => 'image/jpeg',
        ]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.instances.messages.attachment', [
                'instance' => $instance,
                'message' => $message,
            ]))
            ->assertOk();

        $stranger = User::factory()->create(['is_admin' => false, 'projects' => ['ai-chatbot']]);
        $this->actingAs($stranger)
            ->get(route('ai-chatbot.instances.messages.attachment', [
                'instance' => $instance,
                'message' => $message,
            ]))
            ->assertForbidden();
    }
}
