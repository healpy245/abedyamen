<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Support\AppDevelopment\TicketMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('comment', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000'],
            'voices' => ['nullable', 'array', 'max:8'],
            'voices.*' => TicketMedia::fileRules(),
            'notify_user_ids' => ['sometimes', 'array'],
            'notify_user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $body = trim((string) $this->input('body', ''));
            $voices = $this->file('voices', []) ?: [];
            $voiceFiles = array_values(array_filter(
                is_array($voices) ? $voices : [$voices],
                static fn ($file): bool => $file !== null,
            ));

            if ($body === '' && $voiceFiles === []) {
                $validator->errors()->add('body', __('validation.required', ['attribute' => 'body']));
            }

            foreach ($voiceFiles as $index => $file) {
                $mime = strtolower((string) ($file->getMimeType() ?: ''));
                $ext = strtolower((string) $file->getClientOriginalExtension());
                $isAudio = str_starts_with($mime, 'audio/')
                    || in_array($ext, ['webm', 'ogg', 'mp3', 'm4a', 'wav', 'aac'], true);

                if (! $isAudio) {
                    $validator->errors()->add("voices.{$index}", __('validation.mimes', [
                        'attribute' => 'voices',
                        'values' => 'webm, ogg, mp3, m4a, wav, aac',
                    ]));
                }
            }
        });
    }
}
