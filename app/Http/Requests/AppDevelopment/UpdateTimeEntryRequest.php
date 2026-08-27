<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AppDevelopmentTimeEntry $timeEntry */
        $timeEntry = $this->route('timeEntry');

        return $this->user()?->can('editTimeEntry', $timeEntry) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'duration_seconds' => ['required', 'integer', 'min:0', 'max:604800'],
            'note' => ['nullable', 'string', 'max:5000'],
            'edit_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
