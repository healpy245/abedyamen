<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Build short spoken replies from tool results so voice mode can skip a second OpenAI round-trip.
 */
class VoiceFastReplyComposer
{
    /**
     * @param  list<array{name?:string,result?:mixed}>  $toolCallsLog
     */
    public function fromToolCalls(array $toolCallsLog): ?string
    {
        if ($toolCallsLog === []) {
            return null;
        }

        $latest = null;
        foreach (array_reverse($toolCallsLog) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $latest = $entry;
            break;
        }

        if ($latest === null) {
            return null;
        }

        $name = (string) ($latest['name'] ?? '');
        $result = is_array($latest['result'] ?? null) ? $latest['result'] : [];

        return match ($name) {
            'lookup_malan_customer' => $this->fromLookup($result),
            'set_malan_payment_method_preference' => $this->fromPaymentPreference($result),
            'create_malan_support_report' => $this->fromSupportReport($result),
            'charge_malan_saved_payment_method' => $this->fromCharge($result),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function fromLookup(array $result): ?string
    {
        if (($result['found'] ?? false) !== true) {
            $message = trim((string) ($result['message'] ?? ''));

            return $message !== '' ? $this->clip($message) : null;
        }

        $status = strtoupper(trim((string) ($result['customer']['status'] ?? '')));
        $debt = $result['financial']['debt_amount'] ?? null;

        if ($status === 'DEBT_DISCONNECTED' && is_numeric($debt) && (float) $debt > 0) {
            $amount = (int) round((float) $debt);

            return "حسابك مفصول عندي بسبب دين {$amount} شيكل. بتفضّل تسدّد تحويل بنكي ولا فيزا؟";
        }

        if ($status === 'ACTIVE') {
            return 'الحساب شغّال عندي. الأنوار عالراوتر عندك تمام؟ في أحمر أو LOS؟';
        }

        if (($result['escalate'] ?? false) === true || $status === 'UNKNOWN') {
            $message = trim((string) ($result['message'] ?? ''));

            return $message !== ''
                ? $this->clip($message)
                : 'الحساب بدّه فحص من موظف. رح أحوّل المتابعة.';
        }

        if (in_array($status, ['INACTIVE', 'SUSPENDED', 'CANCELLED'], true)) {
            return 'لقيت الحساب. كيف بقدر أساعدك؟';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function fromPaymentPreference(array $result): ?string
    {
        if (($result['success'] ?? false) !== true) {
            $message = trim((string) ($result['message'] ?? ''));

            return $message !== '' ? $this->clip($message) : null;
        }

        $method = (string) ($result['payment_method'] ?? '');

        return match ($method) {
            'bank_transfer' => 'تمام، تحويل بنكي. بعتّلك تفاصيل الحساب؛ ابعت صورة الإثبات لما تحوّل.',
            'visa_saved' => 'تمام، البطاقة المسجّلة. أكّد لي عشان أسحب المبلغ.',
            'visa_other' => 'تمام، بطاقة ثانية. رح أبعتلك رابط الدفع الآمن.',
            default => 'تمام، سجّلت طريقة الدفع.',
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function fromSupportReport(array $result): ?string
    {
        if (($result['success'] ?? false) === true) {
            return 'سجّلت المهمة/البلاغ عندي. رح يتابعوا معك بأقرب وقت.';
        }

        if (($result['error_code'] ?? null) === 'confirmation_required') {
            return 'قبل ما أرفع المهمة لازم أأكد معك النص. بقرا عليك المسودة وإذا موافق أو في ملاحظة بسيطة قولّي.';
        }

        $message = trim((string) ($result['message'] ?? ''));

        return $message !== '' ? $this->clip($message) : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function fromCharge(array $result): ?string
    {
        if (($result['success'] ?? false) === true) {
            return 'تم السحب بنجاح. الأنوار لازم ترجع خلال دقايق.';
        }

        $message = trim((string) ($result['message'] ?? ''));

        return $message !== ''
            ? $this->clip($message)
            : 'السحب ما تم. بتفضّل نجرّب طريقة ثانية؟';
    }

    private function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= 220) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 217)).'…';
    }
}
