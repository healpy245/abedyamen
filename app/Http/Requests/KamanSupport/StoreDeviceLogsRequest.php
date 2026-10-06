<?php

declare(strict_types=1);

namespace App\Http\Requests\KamanSupport;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceLogsRequest extends FormRequest
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
            'logs' => ['required', 'array', 'min:1', 'max:120'],
            'logs.*.id' => ['nullable', 'string', 'max:80'],
            'logs.*.level' => ['nullable', 'string', 'max:16'],
            'logs.*.source' => ['nullable', 'string', 'max:64'],
            'logs.*.message' => ['required', 'string'],
            'logs.*.context' => ['nullable', 'array'],
            'logs.*.at' => ['nullable', 'string', 'max:64'],
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
