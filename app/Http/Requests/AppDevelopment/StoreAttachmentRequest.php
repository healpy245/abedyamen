<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('uploadAttachment', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attachment' => [
                'required',
                'file',
                'max:51200',
                'extensions:jpg,jpeg,png,webp,mp4,mov,pdf,txt,log,zip',
            ],
        ];
    }
}
