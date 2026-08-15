<?php

namespace App\Http\Requests\Voice;

use App\Enums\Voice\VoiceInteractionMode;
use App\Enums\Voice\VoiceProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartVoiceCallRequest extends FormRequest
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
        return [
            'caller_number' => ['nullable', 'string', 'max:32'],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_chatbot_conversations,id'],
            'interaction_mode' => ['nullable', 'string', Rule::enum(VoiceInteractionMode::class)],
            'voice_profile' => ['nullable', 'string', Rule::enum(VoiceProfile::class)],
        ];
    }

    public function interactionMode(): VoiceInteractionMode
    {
        $value = $this->validated('interaction_mode');

        return VoiceInteractionMode::tryFrom((string) $value) ?? VoiceInteractionMode::Text;
    }

    public function voiceProfile(): VoiceProfile
    {
        $value = $this->validated('voice_profile');

        return VoiceProfile::tryFrom((string) $value) ?? VoiceProfile::Woman;
    }

    public function conversationId(): ?int
    {
        $value = $this->validated('conversation_id');

        return $value !== null ? (int) $value : null;
    }
}
