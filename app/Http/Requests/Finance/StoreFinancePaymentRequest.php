<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentRecordStatus;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinancePayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a monthly payment, or settling one that failed (route has a {payment}).
 */
class StoreFinancePaymentRequest extends FormRequest
{
    public const MAX_FILES = 6;

    public function authorize(): bool
    {
        return $this->user()?->canOpenDoor(\App\Enums\Door::Finance) ?? false;
    }

    protected function getRedirectUrl(): string
    {
        $client = $this->route('client');
        if ($client === null && $this->route('payment') instanceof FinancePayment) {
            $client = $this->route('payment')->client;
        }

        return $client instanceof FinanceClient
            ? route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'payments'])
            : parent::getRedirectUrl();
    }

    public function isSettling(): bool
    {
        return $this->route('payment') instanceof FinancePayment;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paid = $this->isSettling() || $this->input('status') === PaymentRecordStatus::Paid->value;

        return [
            'period' => [Rule::requiredIf(! $this->isSettling()), 'date_format:Y-m'],
            'amount' => [Rule::requiredIf(! $this->isSettling()), 'numeric', 'min:0', 'max:1000000'],
            'status' => [Rule::requiredIf(! $this->isSettling()), Rule::enum(PaymentRecordStatus::class)],
            'method' => [$paid ? 'required' : 'nullable', Rule::enum(PaymentMethod::class)],
            'paid_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'files' => ['array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192'],
        ];
    }
}
