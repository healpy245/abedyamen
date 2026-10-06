<?php

declare(strict_types=1);

namespace App\Http\Requests\KamanSupport;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deviceId' => ['required_without:device_id', 'nullable', 'string', 'max:191'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'restaurant' => ['nullable', 'string', 'max:120'],
            'restaurantName' => ['nullable', 'string', 'max:191'],
            'restaurant_name' => ['nullable', 'string', 'max:191'],
            'cashierName' => ['nullable', 'string', 'max:191'],
            'cashierEmail' => ['nullable', 'string', 'max:191'],
            'role' => ['nullable', 'string', 'max:64'],
            'platform' => ['nullable', 'string', 'max:64'],
            'deviceKind' => ['nullable', 'string', 'max:32'],
            'device_kind' => ['nullable', 'string', 'max:32'],
            'appVersion' => ['nullable', 'string', 'max:64'],
            'hostname' => ['nullable', 'string', 'max:191'],
            'deviceName' => ['nullable', 'string', 'max:191'],
            'locale' => ['nullable', 'string', 'max:16'],
            'sessionId' => ['nullable', 'string', 'max:64'],
            'appState' => ['nullable', 'string', 'max:32'],
            'state' => ['nullable', 'string', 'max:32'],
            'loggedIn' => ['nullable', 'boolean'],
            'extra' => ['nullable', 'array'],
            'logs' => ['nullable', 'array', 'max:120'],
            'logs.*.id' => ['nullable', 'string', 'max:80'],
            'logs.*.level' => ['nullable', 'string', 'max:16'],
            'logs.*.source' => ['nullable', 'string', 'max:64'],
            'logs.*.message' => ['required_with:logs', 'string'],
            'logs.*.context' => ['nullable', 'array'],
            'logs.*.at' => ['nullable', 'string', 'max:64'],
            'commandResults' => ['nullable', 'array', 'max:8'],
            'command_results' => ['nullable', 'array', 'max:8'],
            'commandResults.*.id' => ['nullable', 'string', 'max:40'],
            'commandResults.*.ok' => ['nullable', 'boolean'],
            'commandResults.*.result' => ['nullable', 'array'],
            'printers' => ['nullable', 'array', 'max:80'],
            'discovered' => ['nullable', 'array', 'max:80'],
            'kitchenTypes' => ['nullable', 'array', 'max:40'],
            'kitchen_types' => ['nullable', 'array', 'max:40'],
            'scan' => ['nullable', 'array'],
            'lan' => ['nullable', 'array'],
            'lanScan' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->all();
    }
}
