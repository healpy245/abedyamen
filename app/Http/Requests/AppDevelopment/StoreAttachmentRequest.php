<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Support\AppDevelopment\TicketMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

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
            'attachment' => TicketMedia::fileRules(),
            'attachments' => ['nullable', 'array', 'max:12'],
            'attachments.*' => TicketMedia::fileRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->uploadedFiles() === []) {
                $validator->errors()->add('attachment', __('validation.required', ['attribute' => 'attachment']));
            }
        });
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedFiles(): array
    {
        $files = [];
        $single = $this->file('attachment');
        if ($single instanceof UploadedFile) {
            $files[] = $single;
        }

        $many = $this->file('attachments', []) ?: [];
        foreach (is_array($many) ? $many : [$many] as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
