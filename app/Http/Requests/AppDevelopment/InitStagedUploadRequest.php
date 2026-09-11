<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Support\AppDevelopment\TicketMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitStagedUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->canAccessProject(\App\Enums\Project::AppDevelopment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.(TicketMedia::MAX_KILOBYTES * 1024)],
            'mime' => ['nullable', 'string', 'max:127'],
            'kind' => ['nullable', Rule::in(['attachment', 'voice'])],
        ];
    }
}
