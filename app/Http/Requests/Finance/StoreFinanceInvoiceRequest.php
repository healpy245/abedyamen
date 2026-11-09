<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\Finance\InvoiceType;
use App\Models\Finance\FinanceClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canOpenDoor(\App\Enums\Door::Finance) ?? false;
    }

    /**
     * Drops the empty rows the builder leaves behind before validating.
     */
    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->filter(fn ($line): bool => is_array($line) && filled($line['storage_item_id'] ?? null))
            ->values()
            ->all();

        $this->merge(['lines' => $lines]);
    }

    /**
     * Failed validation reopens the client modal on the tab the form lives in.
     */
    protected function getRedirectUrl(): string
    {
        $client = $this->route('client');

        return $client instanceof FinanceClient
            ? route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'invoices'])
            : parent::getRedirectUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(InvoiceType::class)],
            'issued_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.storage_item_id' => ['required', 'integer', Rule::exists('storage_items', 'id')->where('active', true)],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('finance.errors.no_lines'),
            'lines.min' => __('finance.errors.no_lines'),
        ];
    }
}
