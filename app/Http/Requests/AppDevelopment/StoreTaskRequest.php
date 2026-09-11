<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return ($this->user()?->can('create', AppDevelopmentTask::class) ?? false)
            && $ticket instanceof AppDevelopmentTicket
            && ($this->user()?->can('view', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $developerIds = AppDevelopmentMember::assignableDeveloperUserIds();

        return [
            'assignee_id' => [
                'required',
                'integer',
                Rule::in($developerIds === [] ? [0] : $developerIds),
            ],
        ];
    }
}
