<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\Finance\FinanceClient;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Details tab: who to talk to and the business identity. Statuses and billing live on their own tabs.
 */
class UpdateFinanceClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canOpenDoor(\App\Enums\Door::Finance) ?? false;
    }

    /**
     * Failed validation reopens the client modal on the tab the form lives in.
     */
    protected function getRedirectUrl(): string
    {
        $client = $this->route('client');

        return $client instanceof FinanceClient
            ? route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'details'])
            : parent::getRedirectUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s()]*$/'],
            // ח.פ. / ע.מ. — 9 digits in Israel; allow separators people type.
            'business_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\s]*$/'],
            'mfit_client_id' => ['nullable', 'integer', 'min:1', 'max:999999999'],
        ];
    }
}
