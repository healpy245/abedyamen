<?php

declare(strict_types=1);

namespace App\Http\Requests\KamanSupport;

use Illuminate\Foundation\Http\FormRequest;

class StorePosTicketMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audio' => ['nullable', 'file', 'max:20480', 'extensions:wav,m4a,aac,mp3,ogg'],
            'screen' => ['nullable', 'file', 'max:32768', 'extensions:kscr'],
        ];
    }
}
