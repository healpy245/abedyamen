<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use Illuminate\Foundation\Http\FormRequest;

class AppendStagedUploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessProject(\App\Enums\Project::AppDevelopment) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:2048'], // 2 MB ceiling per chunk
        ];
    }
}
