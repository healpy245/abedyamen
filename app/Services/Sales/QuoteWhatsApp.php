<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\Sales\QuoteStatus;
use App\Models\Sales\SalesQuote;
use App\Models\Sales\SalesQuoteSignature;
use App\Models\User;
use App\Services\WhatsApp\KamanWhatsApp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends the client a link to their contract (the quote with the Kaman service agreement) from the Kaman
 * WhatsApp line, the same line Finance notices go out on. The client reads and signs it on that link.
 */
class QuoteWhatsApp
{
    public function __construct(
        private readonly KamanWhatsApp $whatsapp,
        private readonly QuoteContract $contract,
    ) {}

    public function send(SalesQuote $quote, string $phone, string $message, User $agent): SalesQuoteSignature
    {
        $chatId = $this->whatsapp->chatIdFromPhone($phone);
        if ($chatId === '') {
            throw new RuntimeException(__('sales.errors.no_phone'));
        }
        if (! $this->whatsapp->usesWhapi() && trim($this->whatsapp->sendUrl()) === '') {
            throw new RuntimeException(__('sales.errors.whatsapp_unconfigured'));
        }

        $signature = $this->contract->link($quote, $phone, $agent);
        $text = trim($message);
        // The link always goes out, whatever the agent wrote.
        if (! str_contains($text, $signature->url())) {
            $text = trim($text."\n\n".$signature->url());
        }

        $result = $this->whatsapp->sendMessage($this->whatsapp->sendUrl(), $chatId, $text);
        $status = (int) ($result['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            Log::warning('Sales contract WhatsApp not sent', ['sales_quote_id' => $quote->id, 'status' => $status, 'body' => $result['body'] ?? null]);
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

        return $signature;
    }

    /**
     * A lead's quote: the quote PDF (made in the agent's browser) goes to the lead as a file.
     */
    public function sendPdf(SalesQuote $quote, string $phone, string $message, UploadedFile $pdf, string $fileName): void
    {
        $chatId = $this->whatsapp->chatIdFromPhone($phone);
        if ($chatId === '') {
            throw new RuntimeException(__('sales.errors.no_phone'));
        }
        if (! $this->whatsapp->usesWhapi() && $this->whatsapp->uploadUrlFromSendUrl($this->whatsapp->sendUrl()) === null) {
            throw new RuntimeException(__('sales.errors.whatsapp_unconfigured'));
        }

        $bytes = (string) file_get_contents($pdf->getRealPath());
        if ($bytes === '') {
            throw new RuntimeException(__('sales.errors.pdf_failed'));
        }

        $result = $this->whatsapp->sendPdf($chatId, $bytes, $fileName, trim($message));
        $status = (int) ($result['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            Log::warning('Sales quote WhatsApp not sent', ['sales_quote_id' => $quote->id, 'status' => $status, 'body' => $result['body'] ?? null]);
            throw new RuntimeException(__('sales.errors.whatsapp_failed'));
        }

        $quote->forceFill([
            'whatsapp_sent_at' => now(),
            'status' => $quote->status === QuoteStatus::Draft ? QuoteStatus::Sent : $quote->status,
        ])->save();
    }
}
