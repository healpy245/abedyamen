<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\Finance\StorageCategory;
use App\Enums\Sales\QuoteStatus;
use App\Models\Sales\SalesQuote;
use App\Models\Sales\SalesQuoteItem;
use App\Models\Sales\SalesQuoteSignature;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The quote as a Kaman service contract (הסכם התקשרות + הצעת מחיר), and its signing: the agent sends a link,
 * the client signs it, and sending again cancels the earlier link and its signature.
 *
 * The contract is in Hebrew, the language the agreement is written in.
 */
class QuoteContract
{
    public const LANG = 'he';

    /** Largest drawn signature accepted, as PNG bytes. */
    private const MAX_SIGNATURE_BYTES = 600_000;

    /**
     * What the contract shows. A signed contract shows exactly what the client signed; until then it follows the quote.
     *
     * @return array<string, mixed>
     */
    public function data(SalesQuote $quote, ?SalesQuoteSignature $signature = null): array
    {
        if ($signature?->isSigned() && is_array($signature->snapshot)) {
            return $signature->snapshot;
        }

        $quote->loadMissing(['client', 'lead', 'agent:id,name', 'items.catalogItem', 'items.storageItem']);
        $groups = ['monthly' => [], 'services' => [], 'devices' => []];
        foreach ($quote->items as $item) {
            $groups[$this->group($item)][] = [
                'name' => $item->displayName(self::LANG),
                'quantity' => $item->quantityLabel(),
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ];
        }
        $sum = fn (string $group): float => round(array_sum(array_column($groups[$group], 'line_total')), 2);

        return [
            'number' => $quote->number,
            'date' => ($quote->created_at ?? now())->format('d/m/Y'),
            'customer' => $quote->customerName(),
            'contact' => (string) $quote->contact_name,
            'business_number' => (string) $quote->client?->business_number,
            'address' => '',
            'agent' => (string) $quote->agent?->name,
            'notes' => (string) $quote->notes,
            'groups' => $groups,
            'totals' => [
                'monthly' => $sum('monthly'),
                'services' => $sum('services'),
                'devices' => $sum('devices'),
            ],
            'signed' => null,
        ];
    }

    /**
     * Where a line shows on the contract. Services sold as devices before services had their own square are
     * recognised by their storage category.
     */
    private function group(SalesQuoteItem $item): string
    {
        if ($item->isMonthly()) {
            return 'monthly';
        }
        if ($item->kind === 'equipment' && $item->storageItem?->category !== StorageCategory::Service) {
            return 'devices';
        }

        return 'services';
    }

    /**
     * The link the client signs on. A link still waiting is sent again as it is; a signed contract must be
     * cancelled first, so a signature is never dropped by accident.
     */
    public function link(SalesQuote $quote, ?string $phone, User $agent): SalesQuoteSignature
    {
        $current = $quote->signature()->first();
        if ($current?->isSigned()) {
            throw ValidationException::withMessages(['contract' => __('sales.contract.errors.already_signed')]);
        }
        if ($current !== null) {
            $current->forceFill(['phone' => $phone ?: $current->phone, 'sent_by' => $agent->id])->save();

            return $current;
        }

        return $quote->signatures()->create([
            'token' => Str::random(48),
            'status' => SalesQuoteSignature::PENDING,
            'phone' => $phone,
            'sent_by' => $agent->id,
        ]);
    }

    /**
     * The client signs: the drawn signature is kept privately with the contract as it was at that moment.
     *
     * @param  array{signer_name: string, signer_business_number?: string|null, signer_address?: string|null, signature: string}  $input
     */
    public function sign(SalesQuoteSignature $signature, array $input, Request $request): SalesQuoteSignature
    {
        if (! $signature->isPending()) {
            throw ValidationException::withMessages(['signature' => __('sales.contract.errors.not_open')]);
        }

        $png = $this->decodeSignature($input['signature']);

        return DB::transaction(function () use ($signature, $input, $png, $request): SalesQuoteSignature {
            $signature = SalesQuoteSignature::query()->lockForUpdate()->findOrFail($signature->id);
            if (! $signature->isPending()) {
                throw ValidationException::withMessages(['signature' => __('sales.contract.errors.not_open')]);
            }
            $quote = $signature->quote;

            $path = 'sales/signatures/'.$quote->id.'/'.$signature->id.'-'.Str::random(12).'.png';
            Storage::disk(SalesQuoteSignature::DISK)->put($path, $png);

            $signedAt = now();
            $snapshot = $this->data($quote);
            $snapshot['business_number'] = trim((string) ($input['signer_business_number'] ?? '')) ?: $snapshot['business_number'];
            $snapshot['address'] = trim((string) ($input['signer_address'] ?? ''));
            $snapshot['signed'] = [
                'name' => trim($input['signer_name']),
                'day' => $signedAt->format('d'),
                'month' => $signedAt->format('m'),
                'year' => $signedAt->format('Y'),
                'at' => $signedAt->format('d/m/Y H:i'),
            ];

            $signature->forceFill([
                'status' => SalesQuoteSignature::SIGNED,
                'signer_name' => trim($input['signer_name']),
                'signer_business_number' => trim((string) ($input['signer_business_number'] ?? '')) ?: null,
                'signer_address' => trim((string) ($input['signer_address'] ?? '')) ?: null,
                'signature_path' => $path,
                'snapshot' => $snapshot,
                'signed_at' => $signedAt,
                'signed_ip' => $request->ip(),
                'signed_user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ])->save();

            // A quote Finance already has keeps its status; the signature is still recorded.
            if (! $quote->isWithFinance()) {
                $quote->forceFill(['status' => QuoteStatus::Signed])->save();
            }

            return $signature;
        });
    }

    /**
     * Sending the contract again starts over: the link in force (signed or not) is cancelled, and the quote can
     * be changed and sent anew. Not once Finance has the deal.
     */
    public function cancel(SalesQuote $quote, User $user): void
    {
        if ($quote->isWithFinance()) {
            throw ValidationException::withMessages(['contract' => __('sales.errors.locked')]);
        }

        DB::transaction(function () use ($quote, $user): void {
            $quote->signatures()
                ->where('status', '!=', SalesQuoteSignature::CANCELLED)
                ->update([
                    'status' => SalesQuoteSignature::CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->id,
                ]);

            if (in_array($quote->status, [QuoteStatus::Signed, QuoteStatus::Returned], true)) {
                $quote->forceFill(['status' => QuoteStatus::Sent])->save();
            }
        });
        $quote->unsetRelation('signature');
    }

    /**
     * The signature pad sends a PNG data URI. Anything else, or an empty pad, is refused.
     */
    private function decodeSignature(string $value): string
    {
        $fail = fn () => ValidationException::withMessages(['signature' => __('sales.contract.errors.no_signature')]);

        if (! str_starts_with($value, 'data:image/png;base64,')) {
            throw $fail();
        }
        $bytes = base64_decode(substr($value, strlen('data:image/png;base64,')), true);
        if ($bytes === false || strlen($bytes) < 100 || strlen($bytes) > self::MAX_SIGNATURE_BYTES || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw $fail();
        }

        return $bytes;
    }
}
