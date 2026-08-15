<?php

declare(strict_types=1);

namespace App\Services\Voice\Streaming;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Yields voice-stream OpenAI events as they arrive.
 *
 * Events:
 * - ['type'=>'meta','first_token_ms'=>int]
 * - ['type'=>'content','text'=>string]
 * - ['type'=>'done','content'=>string,'tool_calls'=>list,'finish_reason'=>?string,'first_token_ms'=>int]
 */
class OpenAiVoiceStreamClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return Generator<int, array<string, mixed>>
     */
    public function events(string $apiKey, array $payload): Generator
    {
        $payload['stream'] = true;
        $started = microtime(true);
        $firstTokenMs = 0;
        $content = '';
        /** @var array<int, array{id:string,type:string,function:array{name:string,arguments:string}}> $toolCalls */
        $toolCalls = [];
        $finishReason = null;
        $sawToolCalls = false;
        $emittedFirst = false;

        try {
            $response = Http::withToken($apiKey)
                ->accept('text/event-stream')
                ->withHeaders(['Content-Type' => 'application/json'])
                ->timeout(45)
                ->withOptions(['stream' => true])
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (Throwable $e) {
            Log::warning('Voice OpenAI stream failed to connect', ['error' => $e->getMessage()]);
            throw new RuntimeException('Unable to reach the AI provider for voice streaming.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Voice OpenAI stream failed with status '.$response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    break 2;
                }

                $json = json_decode($data, true);
                if (! is_array($json)) {
                    continue;
                }

                $delta = $json['choices'][0]['delta'] ?? null;
                $finishReason = $json['choices'][0]['finish_reason'] ?? $finishReason;
                if (! is_array($delta)) {
                    continue;
                }

                if ($firstTokenMs === 0) {
                    $firstTokenMs = (int) round((microtime(true) - $started) * 1000);
                }

                $deltaTools = $delta['tool_calls'] ?? null;
                if (is_array($deltaTools) && $deltaTools !== []) {
                    $sawToolCalls = true;
                    foreach ($deltaTools as $toolDelta) {
                        if (! is_array($toolDelta)) {
                            continue;
                        }
                        $index = (int) ($toolDelta['index'] ?? 0);
                        if (! isset($toolCalls[$index])) {
                            $toolCalls[$index] = [
                                'id' => (string) ($toolDelta['id'] ?? ''),
                                'type' => 'function',
                                'function' => [
                                    'name' => (string) ($toolDelta['function']['name'] ?? ''),
                                    'arguments' => (string) ($toolDelta['function']['arguments'] ?? ''),
                                ],
                            ];
                            continue;
                        }
                        if (isset($toolDelta['id']) && is_string($toolDelta['id']) && $toolDelta['id'] !== '') {
                            $toolCalls[$index]['id'] = $toolDelta['id'];
                        }
                        if (isset($toolDelta['function']['name']) && is_string($toolDelta['function']['name'])) {
                            $toolCalls[$index]['function']['name'] .= $toolDelta['function']['name'];
                        }
                        if (isset($toolDelta['function']['arguments']) && is_string($toolDelta['function']['arguments'])) {
                            $toolCalls[$index]['function']['arguments'] .= $toolDelta['function']['arguments'];
                        }
                    }
                }

                $piece = $delta['content'] ?? null;
                if (! $sawToolCalls && is_string($piece) && $piece !== '') {
                    if (! $emittedFirst) {
                        yield ['type' => 'meta', 'first_token_ms' => $firstTokenMs];
                        $emittedFirst = true;
                    }
                    $content .= $piece;
                    yield ['type' => 'content', 'text' => $piece];
                }
            }
        }

        if (! $emittedFirst && $firstTokenMs > 0) {
            yield ['type' => 'meta', 'first_token_ms' => $firstTokenMs];
        }

        yield [
            'type' => 'done',
            'content' => trim($content),
            'tool_calls' => array_values($toolCalls),
            'finish_reason' => is_string($finishReason) ? $finishReason : null,
            'first_token_ms' => $firstTokenMs,
        ];
    }

    /**
     * Compatibility wrapper used by non-progressive callers.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(string): void  $onContentDelta
     * @return array{content:string,tool_calls:list,finish_reason:?string,first_token_ms:int}
     */
    public function stream(string $apiKey, array $payload, callable $onContentDelta): array
    {
        $result = [
            'content' => '',
            'tool_calls' => [],
            'finish_reason' => null,
            'first_token_ms' => 0,
        ];

        foreach ($this->events($apiKey, $payload) as $event) {
            if (($event['type'] ?? null) === 'content') {
                $onContentDelta((string) $event['text']);
            }
            if (($event['type'] ?? null) === 'done') {
                $result = [
                    'content' => (string) ($event['content'] ?? ''),
                    'tool_calls' => is_array($event['tool_calls'] ?? null) ? $event['tool_calls'] : [],
                    'finish_reason' => $event['finish_reason'] ?? null,
                    'first_token_ms' => (int) ($event['first_token_ms'] ?? 0),
                ];
            }
            if (($event['type'] ?? null) === 'meta') {
                $result['first_token_ms'] = (int) ($event['first_token_ms'] ?? 0);
            }
        }

        return $result;
    }
}
