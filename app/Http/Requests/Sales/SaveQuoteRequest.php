<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Enums\Door;
use App\Models\Sales\SalesCatalogItem;
use App\Models\Sales\SalesQuoteItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isChatbotOnly();
    }

    /**
     * Drops the empty rows the builder leaves behind before validating.
     */
    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->filter(fn ($line): bool => is_array($line) && filled($line['kind'] ?? null))
            ->values()
            ->all();

        $this->merge(['lines' => $lines]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'finance_client_id' => ['required_without:agent_lead_id', 'prohibits:agent_lead_id', 'nullable', 'integer', Rule::exists('finance_clients', 'id')],
            'agent_lead_id' => [Rule::prohibitedIf(fn (): bool => ! $this->user()->canOpen(Door::Agents, 'leads')), 'nullable', 'integer', Rule::exists('agent_leads', 'id')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:60'],
            'lines.*.kind' => ['required', Rule::in(SalesQuoteItem::KINDS)],
            'lines.*.ref_id' => ['nullable', 'integer', 'required_unless:lines.*.kind,custom'],
            'lines.*.name' => ['nullable', 'string', 'max:191', 'required_if:lines.*.kind,custom'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.billing' => ['nullable', Rule::in(SalesCatalogItem::BILLINGS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'finance_client_id.required_without' => __('sales.errors.no_client'),
            'finance_client_id.prohibits' => __('sales.errors.no_client'),
            'lines.required' => __('sales.errors.no_lines'),
            'lines.min' => __('sales.errors.no_lines'),
        ];
    }
}
