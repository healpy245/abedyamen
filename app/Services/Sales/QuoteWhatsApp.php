<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\Sales\QuoteStatus;
use App\Models\Sales\SalesQuote;
use App\Services\WhatsApp\KamanWhatsApp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends a quote PDF to the client from the Kaman WhatsApp line, the same line Finance notices go out on.
 * The PDF itself is made in the agent's browser and uploaded with the request.
 */
class QuoteWhatsApp
{
    public function __construct(
        private readonly KamanWhatsApp $whatsapp,
    ) {}

    public function send(SalesQuote $quote, string $phone, string $message, UploadedFile $pdf, string $fileName): void
    {
        $chatId = $this->whatsapp->chatIdFromPhone($phone);
        if ($chatId === '') {
            throw new RuntimeException(__('sales.errors.no_phone'));
        }

        $sendUrl = $this->whatsapp->sendUrl();
        $uploadUrl = $this->whatsapp->uploadUrlFromSendUrl($sendUrl);
        if (! $this->whatsapp->usesWhapi() && $uploadUrl === null) {
            throw new RuntimeException(__('sales.errors.whatsapp_unconfigured'));
        }

        $bytes = (string) file_get_contents($pdf->getRealPath());
        if ($bytes === '') {
            throw new RuntimeException(__('sales.errors.pdf_failed'));
        }
        $message = trim($message);

        $result = $this->whatsapp->sendPdf($chatId, $bytes, $fileName, $message);
        $status = (int) ($result['status'] ?? 0);
        $body = $result['body'] ?? null;

        if ($status < 200 || $status >= 300) {
            Log::warning('Sales quote WhatsApp not sent', ['sales_quote_id' => $quote->id, 'status' => $status, 'body' => $body]);
            throw new RuntimeException(__('sales.errors.whatsapp_failed'));
        }

        // The client's number is remembered for next time.
        $client = $quote->client;
        if ($client !== null && blank($client->phone)) {
            $client->forceFill(['phone' => $phone])->save();
        }

        $quote->forceFill([
            'whatsapp_sent_at' => now(),
            'status' => $quote->status === QuoteStatus::Draft ? QuoteStatus::Sent : $quote->status,
        ])->save();
    }
}
