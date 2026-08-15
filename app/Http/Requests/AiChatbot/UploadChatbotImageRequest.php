<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChatbot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadChatbotImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) ceil(((int) config('malan.media.max_bytes', 5 * 1024 * 1024)) / 1024);

        return [
            'image' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf'])
                    ->max($maxKb),
            ],
            'caption' => ['nullable', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_chatbot_conversations,id'],
        ];
    }
}
