<?php

namespace App\Http\Requests\Voice;

use App\Enums\Voice\VoiceProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SynthesizeSpeechRequest extends FormRequest
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
            'text' => ['required', 'string', 'max:4000'],
            'voice_profile' => ['nullable', 'string', Rule::enum(VoiceProfile::class)],
            'locale' => ['nullable', 'string', 'max:12'],
        ];
    }

    public function voiceProfile(): VoiceProfile
    {
        $value = $this->validated('voice_profile');

        return VoiceProfile::tryFrom((string) $value) ?? VoiceProfile::Woman;
    }

    public function locale(): string
    {
        $value = $this->validated('locale');

        return is_string($value) && $value !== ''
            ? $value
            : (string) app()->getLocale();
    }
}
