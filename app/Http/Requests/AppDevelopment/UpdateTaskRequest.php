<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\AppDevelopmentTicketPriority;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AppDevelopmentTask $task */
        $task = $this->route('task');

        return $this->user()?->can('update', $task) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $developerIds = AppDevelopmentMember::assignableDeveloperUserIds();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'assignee_id' => ['nullable', 'integer', Rule::in($developerIds === [] ? [0] : $developerIds)],
            'priority' => ['required', Rule::enum(AppDevelopmentTicketPriority::class)],
            'status' => ['nullable', Rule::enum(AppDevelopmentTaskStatus::class)],
            'due_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('assignee_id') === '' || $this->input('assignee_id') === null) {
            $this->merge(['assignee_id' => null]);
        }
    }
}
