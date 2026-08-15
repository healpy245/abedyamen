<?php

declare(strict_types=1);

namespace App\Services\Malan\Proof;

use App\Data\Malan\BankTransferProofVerificationResult;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanPaymentProof;
use App\Services\Malan\Contracts\BankTransferProofVerifier;
use App\Services\Malan\Contracts\RequestServiceReactivation;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\MalanConversationMemoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class MalanPaymentProofService
{
    public function __construct(
        protected MalanConversationContextService $contextService,
        protected MalanConversationMemoryService $memoryService,
        protected BankTransferProofVerifier $verifier,
        protected RequestServiceReactivation $reactivation,
    ) {}

    /**
     * @return array{
     *     handled: bool,
     *     awaiting_proof: bool,
     *     proof?: MalanPaymentProof,
     *     verification?: BankTransferProofVerificationResult,
     *     customer_message: string,
     *     reactivation?: array<string, mixed>
     * }
     */
    public function handleIncomingProofFile(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $relativePath,
        string $mimeType,
        ?string $greenApiMessageId = null,
    ): array {
        if (! $instance->hasMalanIntegration()) {
            return [
                'handled' => false,
                'awaiting_proof' => false,
                'customer_message' => 'استلمت الملف، بس مش واضح كيف بقدر أساعدك فيه. ممكن توضحلي؟',
            ];
        }

        if ($greenApiMessageId) {
            $existing = MalanPaymentProof::query()
                ->where('chatbot_instance_id', $instance->id)
                ->where('greenapi_message_id', $greenApiMessageId)
                ->first();

            if ($existing !== null) {
                return [
                    'handled' => true,
                    'awaiting_proof' => false,
                    'proof' => $existing,
                    'customer_message' => 'استلمت هالصورة من قبل وبتم متابعتها.',
                ];
            }
        }

        $context = $this->memoryService->ensureAwaitingBankTransferProof($conversation, $instance);
        $awaiting = $context !== null
            && $context->hasVerifiedCustomer()
            && $context->pending_flow === 'awaiting_bank_transfer_proof'
            && $context->payment_method === 'bank_transfer';

        if (! $awaiting) {
            return [
                'handled' => false,
                'awaiting_proof' => false,
                'customer_message' => 'استلمت الصورة، بس حاليًا ما عندي طلب تحويل بنكي معلّق بهالمحادثة. قصدك إيش بالصورة؟',
            ];
        }

        if ($context->debt_amount === null) {
            return [
                'handled' => true,
                'awaiting_proof' => true,
                'customer_message' => 'وصلتني الصورة، بس مبلغ الدين مش ظاهر عندي بشكل موثوق. رح أحوّل الموضوع للجباية تتابع معك.',
            ];
        }

        $disk = (string) config('malan.media.disk', 'local');
        $absolute = Storage::disk($disk)->path($relativePath);
        $today = Carbon::now((string) config('malan.proof.timezone', 'Asia/Jerusalem'))->toDateString();

        $verification = $this->verifier->verify($absolute, [
            'expected_amount' => (float) $context->debt_amount,
            'expected_date' => $today,
            'bank_name' => config('malan.bank.name'),
            'bank_branch' => config('malan.bank.branch'),
            'bank_account' => config('malan.bank.account'),
            'mime_type' => $mimeType,
        ]);

        $proof = MalanPaymentProof::query()->create([
            'chatbot_instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'external_customer_id' => $context->verified_customer_id,
            'payment_method' => 'bank_transfer',
            'expected_amount' => $context->debt_amount,
            'detected_amount' => $verification->detectedAmount,
            'detected_date' => $verification->detectedDate,
            'reference_number' => $verification->referenceNumber,
            'file_path' => $relativePath,
            'mime_type' => $mimeType,
            'verification_status' => $verification->status,
            'confidence' => $verification->confidence,
            'verification_details' => $verification->toArray(),
            'greenapi_message_id' => $greenApiMessageId,
        ]);

        if ($verification->status === BankTransferProofVerificationResult::STATUS_VERIFIED) {
            $reactivation = $this->reactivation->request([
                'customer_id' => (string) $context->verified_customer_id,
                'conversation_id' => $conversation->id,
                'reason' => 'bank_transfer_proof_verified',
                'channel' => 'whatsapp',
                'instance_id' => $instance->id,
            ]);

            $this->contextService->setPendingFlow($conversation, $instance, 'payment_proof_verified_pending_reactivation');

            $message = 'وصلتني صورة التحويل وتم التحقق من التفاصيل الأساسية بنجاح. سجلت طلب إعادة الخدمة، ومن المفروض تتم متابعة إعادة الخط بأقرب وقت. شكرًا على تعاونك.';
            if (($reactivation['integration_pending'] ?? false) === true) {
                // Keep safe wording; do not claim instant reactivation.
                $message = 'وصلتني صورة التحويل وتم التحقق من التفاصيل الأساسية بنجاح. سجلت طلب إعادة الخدمة، ومن المفروض تتم متابعة إعادة الخط بأقرب وقت. شكرًا على تعاونك.';
            }

            return [
                'handled' => true,
                'awaiting_proof' => true,
                'proof' => $proof,
                'verification' => $verification,
                'reactivation' => $reactivation,
                'customer_message' => $message,
            ];
        }

        if ($verification->status === BankTransferProofVerificationResult::STATUS_REJECTED) {
            $reasons = $verification->suspicionReasons;
            $isDemo = in_array('demo_or_fake_document', $reasons, true)
                || (bool) ($verification->details['is_demo_or_fake'] ?? false);

            $customerMessage = $isDemo
                ? 'هالصورة مش مقبولة كإثبات تحويل حقيقي — ظاهر عليها علامات تجربة/DEMO أو مستند وهمي. ابعتلي صورة تحويل حقيقية من البنك تظهر المبلغ وتاريخ اليوم وמספר אסמכתה، أو رح أحوّلك للجباية.'
                : 'فحصت صورة التحويل وما قدرت أطابق التفاصيل الأساسية (مثل المبلغ أو التاريخ أو מספר אסמכתה). ابعتلي صورة أوضح يظهر فيها المبلغ وتاريخ اليوم وמספר אסמכתה، أو رح أحوّلك للجباية.';

            return [
                'handled' => true,
                'awaiting_proof' => true,
                'proof' => $proof,
                'verification' => $verification,
                'customer_message' => $customerMessage,
            ];
        }

        return [
            'handled' => true,
            'awaiting_proof' => true,
            'proof' => $proof,
            'verification' => $verification,
            'customer_message' => 'وصلتني صورة التحويل وسجّلتها للمراجعة. في تفاصيل تحتاج تدقيق موظف، ورح تتابع الجباية معك بأقرب وقت.',
        ];
    }
}
