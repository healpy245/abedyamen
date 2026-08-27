<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Enums\AppDevelopmentAppType;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketType;
use App\Support\AppDevelopment\TicketMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(AppDevelopmentTicketType::class)],
            'priority' => ['required', Rule::enum(AppDevelopmentTicketPriority::class)],
            'app_types' => ['required', 'array', 'min:1'],
            'app_types.*' => ['required', 'string', Rule::enum(AppDevelopmentAppType::class)],
            'description' => ['nullable', 'string', 'max:20000'],
            'voices' => ['nullable', 'array', 'max:8'],
            'voices.*' => TicketMedia::fileRules(),
            'attachments' => ['nullable', 'array', 'max:12'],
            'attachments.*' => TicketMedia::fileRules(),
            'staged_attachments' => ['nullable', 'array', 'max:12'],
            'staged_attachments.*' => ['uuid'],
            'staged_voices' => ['nullable', 'array', 'max:8'],
            'staged_voices.*' => ['uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => $this->input('type') ?: AppDevelopmentTicketType::Bug->value,
            'description' => is_string($this->input('description'))
                ? trim($this->input('description'))
                : '',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $description = trim((string) $this->input('description', ''));
            $voices = $this->file('voices', []) ?: [];
            $voiceFiles = array_values(array_filter(
                is_array($voices) ? $voices : [$voices],
                static fn ($file): bool => $file instanceof UploadedFile,
            ));
            $stagedVoices = array_values(array_filter(
                (array) $this->input('staged_voices', []),
                static fn ($uuid): bool => is_string($uuid) && $uuid !== '',
            ));

            if ($description === '' && $voiceFiles === [] && $stagedVoices === []) {
                $validator->errors()->add(
                    'description',
                    __('validation.required', ['attribute' => 'description']),
                );
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
