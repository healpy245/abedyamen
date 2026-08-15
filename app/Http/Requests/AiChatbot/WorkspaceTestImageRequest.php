<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class WorkspaceTestImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('reset')) {
            $this->merge(['reset' => true]);
        }
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
            'channel' => ['nullable', 'string', Rule::in([
                ChatbotConversation::CHANNEL_WEB,
                ChatbotConversation::CHANNEL_WHATSAPP,
                ChatbotConversation::CHANNEL_VOICE,
                ChatbotConversation::CHANNEL_TEST,
            ])],
            'phone' => ['nullable', 'string', 'max:32'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'conversation_id' => ['nullable', 'integer'],
            'reset' => ['sometimes', 'boolean'],
        ];
    }
}
