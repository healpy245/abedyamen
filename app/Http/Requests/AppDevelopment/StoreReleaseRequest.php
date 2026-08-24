<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Models\AppDevelopment\AppDevelopmentTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('uploadRelease', \App\Models\AppDevelopment\AppDevelopmentRelease::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version_name' => ['required', 'string', 'max:64'],
            'version_code' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'title' => ['nullable', 'string', 'max:255'],
            'release_notes' => ['nullable', 'string', 'max:20000'],
            'apk' => ['required', 'file', 'max:204800', 'extensions:apk'],
            'ticket_ids' => ['nullable', 'array'],
            'ticket_ids.*' => ['integer', Rule::exists(AppDevelopmentTicket::class, 'id')],
        ];
    }
}
