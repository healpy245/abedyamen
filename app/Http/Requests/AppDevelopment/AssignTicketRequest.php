<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Models\AppDevelopment\AppDevelopmentMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('assign', $ticket) ?? false);
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
            'assigned_to' => ['required', 'integer', Rule::in($developerIds)],
        ];
    }
}
