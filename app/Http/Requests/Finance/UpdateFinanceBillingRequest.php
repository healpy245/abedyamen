<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\Finance\PaymentStatus;
use App\Models\Finance\FinanceClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Monthly payments tab: payment status, method on file and next billing date.
 */
class UpdateFinanceBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canOpenDoor(\App\Enums\Door::Finance) ?? false;
    }

    protected function getRedirectUrl(): string
    {
        $client = $this->route('client');

        return $client instanceof FinanceClient
            ? route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'payments'])
            : parent::getRedirectUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'next_billing_date' => ['nullable', 'date'],
        ];
    }
}
