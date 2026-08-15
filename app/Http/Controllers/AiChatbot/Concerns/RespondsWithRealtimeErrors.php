<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot\Concerns;

use App\Exceptions\Voice\RealtimeUpstreamException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

trait RespondsWithRealtimeErrors
{
    /**
     * @param  array<string, mixed>  $extra
     */
    protected function realtimeErrorResponse(string $message, int $status, array $extra = []): JsonResponse
    {
        $payload = ['message' => $message];

        if (config('app.debug')) {
            $payload = array_merge($payload, $extra);
        }

        return response()->json($payload, $status);
    }

    protected function realtimeUpstreamResponse(RealtimeUpstreamException $exception): JsonResponse
    {
        return $this->realtimeErrorResponse(
            $exception->getMessage(),
            Response::HTTP_BAD_GATEWAY,
            $exception->debugContext(),
        );
    }

    protected function realtimeValidationResponse(ValidationException $exception): JsonResponse
    {
        $payload = [
            'message' => __('voice.realtime.errors.invalid_sdp'),
            'errors' => $exception->errors(),
        ];

        if (! config('app.debug')) {
            unset($payload['errors']);
        }

        return response()->json($payload, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
