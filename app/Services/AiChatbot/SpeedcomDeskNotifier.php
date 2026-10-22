<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanSupportReport;
use App\Services\Malan\MalanSensitiveDataMasker;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mamdouh handoff: a fixed WhatsApp template to the Speedcom desk, instead of a Malan CRM lead or task.
 */
class SpeedcomDeskNotifier
{
    public function __construct(
        protected ChatbotGreenApiService $greenApi,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function sendLead(ChatbotInstance $instance, ChatbotConversation $conversation, array $input): array
    {
        $name = trim((string) ($input['full_name'] ?? $input['name'] ?? ''));
        $phone = $this->customerPhone($conversation, (string) ($input['phone'] ?? ''));
        $city = trim((string) ($input['city_name'] ?? $input['city'] ?? ''));
        $note = $this->oneLine((string) ($input['note'] ?? ''));
        $fiber = $this->fiberLabel($input['with_fiber'] ?? null);

        $message = implode("\n", [
            'اشتراك جديد — سبيدكوم',
            'الاسم: '.$name,
            'التلفون: '.$phone,
            'البلدة: '.$city,
            'فايبر بالبيت: '.$fiber,
            'ملاحظة: '.($note !== '' ? $note : '—'),
        ]);

        return $this->deliver($instance, $conversation, 'new_lead', $name, $phone, $message, [
            'city_name' => $city,
            'with_fiber' => $fiber,
        ], (string) ($input['channel'] ?? 'web'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function sendTask(ChatbotInstance $instance, ChatbotConversation $conversation, array $input): array
    {
        $department = (string) ($input['department'] ?? 'technical');
        if (! in_array($department, ['technical', 'accounting'], true)) {
            $department = 'technical';
        }

        $name = $this->customerName($conversation, (string) ($input['customer_name'] ?? $input['full_name'] ?? ''));
        $phone = $this->customerPhone($conversation, (string) ($input['phone'] ?? ''));
        $subject = $this->oneLine((string) ($input['subject'] ?? $input['summary'] ?? ''));
        if ($subject === '') {
            return [
                'success' => false,
                'message' => 'نص البلاغ فاضي. لازم ملخص متفق عليه مع الزبون.',
            ];
        }

        $lines = $department === 'accounting'
            ? [
                'متابعة محاسبة — سبيدكوم',
                'الاسم: '.$name,
                'التلفون: '.$phone,
                'التفاصيل: '.$subject,
            ]
            : [
                'مشكلة تقنية — سبيدكوم',
                'الاسم: '.$name,
                'التلفون: '.$phone,
                'الراوتر: '.$this->routerPowerLabel((string) ($input['router_power'] ?? '')),
                'الضوء: '.$this->routerLightLabel((string) ($input['router_light'] ?? '')),
                'النت: '.$this->internetLabel((string) ($input['internet'] ?? '')),
                'التفاصيل: '.$subject,
            ];

        return $this->deliver($instance, $conversation, $department, $name, $phone, implode("\n", $lines), [
            'department' => $department,
            'router_power' => (string) ($input['router_power'] ?? ''),
            'router_light' => (string) ($input['router_light'] ?? ''),
            'internet' => (string) ($input['internet'] ?? ''),
        ], (string) ($input['channel'] ?? 'web'));
    }

    public function deskChatId(): string
    {
        $digits = preg_replace('/\D+/', '', $this->deskPhone()) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '972'.substr($digits, 1);
        }

        return $digits.'@c.us';
    }

    public function deskPhone(): string
    {
        $phone = trim((string) config('malan.speedcom.desk_phone', '0584680001'));

        return $phone !== '' ? $phone : '0584680001';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function deliver(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $issueType,
        string $name,
        string $phone,
        string $message,
        array $extra,
        string $channel,
    ): array {
        $windowMinutes = (int) config('malan.leads.duplicate_window_minutes', 30);
        $duplicate = MalanSupportReport::query()
            ->where('chatbot_instance_id', $instance->id)
            ->where('conversation_id', $conversation->id)
            ->where('issue_type', $issueType)
            ->where('created_at', '>=', Carbon::now()->subMinutes($windowMinutes))
            ->latest('id')
            ->first();

        if ($duplicate !== null) {
            return [
                'success' => true,
                'duplicate' => true,
                'report_id' => $duplicate->id,
                'message' => 'البلاغ مبعوث مسبقًا لهالمحادثة. الفريق رح يتابع.',
            ];
        }

        $sendUrl = trim((string) $instance->greenapi_url);
        $canSend = $this->greenApi->whapiTransportReady($sendUrl)
            || ($sendUrl !== '' && str_contains($sendUrl, '/sendMessage/'));

        if (! $canSend) {
            return [
                'success' => false,
                'error_code' => 'whatsapp_not_configured',
                'message' => 'ما قدرت أبعث البلاغ هلق. خط الواتساب مش جاهز.',
            ];
        }

        try {
            $result = $this->greenApi->sendMessage($sendUrl, $this->deskChatId(), $message, 1000);
        } catch (Throwable $e) {
            Log::warning('Speedcom desk WhatsApp failed', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
                'issue_type' => $issueType,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'صار خلل مؤقت وما قدرت أبعث البلاغ. حاول بعد شوي.',
            ];
        }

        $status = (int) ($result['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            Log::warning('Speedcom desk WhatsApp rejected', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
                'issue_type' => $issueType,
                'status' => $status,
            ]);

            return [
                'success' => false,
                'message' => 'ما قدرت أبعث البلاغ هلق. حاول بعد شوي.',
            ];
        }

        $report = MalanSupportReport::query()->create([
            'chatbot_instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'external_customer_id' => 'speedcom:'.$phone,
            'customer_name' => $name,
            'customer_phone_masked' => MalanSensitiveDataMasker::maskPhone($phone),
            'issue_type' => $issueType,
            'summary' => $message,
            'status' => 'OPEN',
            'source_channel' => $channel,
            'metadata' => array_merge($extra, [
                'via' => 'speedcom_whatsapp',
                'desk_phone' => $this->deskPhone(),
                'green_status' => $status,
            ]),
        ]);

        $customerMessage = match ($issueType) {
            'new_lead' => 'تم تسجيل الطلب. مندوب رح يتواصل معك.',
            'accounting' => 'تم بعث المتابعة للمحاسبة. رح يتواصلوا معك.',
            default => 'تم بعث البلاغ للدعم الفني. رح يتواصلوا معك.',
        };

        return [
            'success' => true,
            'duplicate' => false,
            'report_id' => $report->id,
            'department' => $issueType === 'new_lead' ? null : $issueType,
            'message' => $customerMessage,
            'instruction' => 'Tell the customer the team will follow up. NEVER mention the desk phone number or that a WhatsApp template was sent.',
        ];
    }

    private function customerName(ChatbotConversation $conversation, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            $raw = trim((string) ($conversation->contact_name ?: ''));
        }

        return $raw !== '' ? $raw : 'غير معروف';
    }

    private function customerPhone(ChatbotConversation $conversation, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'whatsapp_chat_phone') === 0) {
            $raw = trim((string) ($conversation->contact_phone ?: $conversation->external_chat_id ?: ''));
        }

        return $this->displayPhone($raw);
    }

    private function displayPhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '972') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 3);
        }

        return $digits !== '' ? $digits : 'غير متوفر';
    }

    private function fiberLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'غير محدد';
        }

        return ((int) $value) === 1 ? 'نعم' : 'لا';
    }

    private function routerPowerLabel(string $value): string
    {
        return match ($value) {
            'off' => 'مطفي',
            'on' => 'ضاوي',
            default => 'غير محدد',
        };
    }

    private function routerLightLabel(string $value): string
    {
        return match ($value) {
            'off' => 'مطفي',
            'red_weak' => 'أحمر ضعيف',
            'red' => 'أحمر',
            'normal' => 'عادي',
            default => 'غير محدد',
        };
    }

    private function internetLabel(string $value): string
    {
        return match ($value) {
            'none' => 'مقطوع بالمرة',
            'weak' => 'ضعيف',
            'ok' => 'شغال',
            default => 'غير محدد',
        };
    }

    private function oneLine(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value;
    }
}
