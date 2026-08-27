<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Enums\AppDevelopmentTicketPriority;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AppDevelopmentTask::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $developerIds = AppDevelopmentMember::assignableDeveloperUserIds();

        return [
            'ticket_id' => [
                Rule::requiredIf(fn (): bool => ! $this->route('ticket') instanceof AppDevelopmentTicket),
                'nullable',
                'integer',
                Rule::exists('app_development_tickets', 'id'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'assignee_id' => ['nullable', 'integer', Rule::in($developerIds === [] ? [0] : $developerIds)],
            'priority' => ['required', Rule::enum(AppDevelopmentTicketPriority::class)],
            'due_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $ticket = $this->route('ticket');
        if ($ticket instanceof AppDevelopmentTicket) {
            $this->merge(['ticket_id' => $ticket->id]);
        }

        if ($this->input('assignee_id') === '' || $this->input('assignee_id') === null) {
            $this->merge(['assignee_id' => null]);
        }
    }
}
