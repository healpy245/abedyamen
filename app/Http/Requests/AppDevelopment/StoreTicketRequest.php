<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketType;
use App\Models\AppDevelopment\AppDevelopmentMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $developerIds = AppDevelopmentMember::query()
            ->where('role', AppDevelopmentRole::Developer)
            ->pluck('user_id')
            ->all();

        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AppDevelopmentTicketType::class)],
            'priority' => ['required', Rule::enum(AppDevelopmentTicketPriority::class)],
            'description' => ['required', 'string', 'max:20000'],
            'assigned_to' => ['nullable', 'integer', Rule::in($developerIds)],
            'attachments' => ['nullable', 'array', 'max:8'],
            'attachments.*' => [
                'file',
                'max:51200',
                'extensions:jpg,jpeg,png,webp,mp4,mov,pdf,txt,log,zip',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('assigned_to') === '' || $this->input('assigned_to') === null) {
            $this->merge(['assigned_to' => null]);
        }
    }
}
