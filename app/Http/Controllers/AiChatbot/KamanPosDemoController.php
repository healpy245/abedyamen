<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Services\AiChatbot\KamanPosDemoVideoService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KamanPosDemoController extends Controller
{
    public function show(string $token, KamanPosDemoVideoService $demo): StreamedResponse
    {
        return $demo->streamByToken($token);
    }
}
