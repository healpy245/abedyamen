<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Finance\FinanceClient;
use App\Services\WhatsApp\KamanWhatsApp;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends finance notices from the Kaman WhatsApp line, the same line App Development uses.
 */
class FinanceWhatsApp
{
    public function __construct(
        private readonly KamanWhatsApp $whatsapp,
    ) {}

    public function sendFailedPaymentNotice(FinanceClient $client, string $lang): void
    {
        if (! in_array($lang, FinanceClient::LANGUAGES, true)) {
            throw new RuntimeException(__('finance.errors.bad_language'));
        }

        $chatId = $this->whatsapp->chatIdFromPhone((string) $client->phone);
        if ($chatId === '') {
            throw new RuntimeException(__('finance.errors.no_phone'));
        }

        $sendUrl = $this->whatsapp->sendUrl();
        if ($sendUrl === '') {
            throw new RuntimeException(__('finance.errors.whatsapp_unconfigured'));
        }

        $message = __('finance.whatsapp.payment_failed', ['name' => $client->name], $lang);
        $result = $this->whatsapp->sendMessage($sendUrl, $chatId, $message);
        $status = (int) ($result['status'] ?? 0);

        if ($status < 200 || $status >= 300) {
            Log::warning('Finance failed-payment WhatsApp not sent', [
                'finance_client_id' => $client->id,
                'status' => $status,
                'body' => $result['body'] ?? null,
            ]);
            throw new RuntimeException(__('finance.errors.whatsapp_failed'));
        }

        $client->forceFill(['failed_notice_at' => now(), 'lang' => $lang])->save();
    }
}
