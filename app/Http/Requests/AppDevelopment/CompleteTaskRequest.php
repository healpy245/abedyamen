<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Models\AppDevelopment\AppDevelopmentTask;
use Illuminate\Foundation\Http\FormRequest;

class CompleteTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AppDevelopmentTask $task */
        $task = $this->route('task');

        return $this->user()?->can('complete', $task) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'completion_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
