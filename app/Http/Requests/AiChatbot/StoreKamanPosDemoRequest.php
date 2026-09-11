<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChatbot;

use App\Services\AiChatbot\KamanPosDemoVideoService;
use Illuminate\Foundation\Http\FormRequest;

class StoreKamanPosDemoRequest extends FormRequest
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
        $ext = implode(',', KamanPosDemoVideoService::ALLOWED_EXTENSIONS);

        return [
            'video' => [
                'required',
                'file',
                'mimes:'.$ext,
                'max:'.KamanPosDemoVideoService::MAX_KILOBYTES,
            ],
        ];
    }
}
