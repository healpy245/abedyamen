<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

class ConnectRealtimeCallRequest extends FormRequest
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
            'sdp' => ['required', 'string', 'min:20', 'regex:/^v=0/m'],
        ];
    }

    public function offerSdp(): string
    {
        $sdp = (string) $this->validated('sdp');

        // Preserve trailing SDP line endings; OpenAI rejects offers without a final CRLF.
        return ltrim($sdp);
    }
}
