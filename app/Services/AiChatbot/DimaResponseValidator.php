<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Checks that a model reply is usable JSON. It does not score political wording.
 */
class DimaResponseValidator
{
    /**
     * @param  array<string, mixed>  $prior
     * @return array{text:string,stance:string,note:string,memory:array{known:string,topic:string,answered:string,open_question:string,concern:string,wants_to_continue:bool}}|null
     */
    public function accept(?string $raw, array $prior): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            return null;
        }
        $text = trim((string) ($parsed['reply'] ?? ''));
        if ($text === '') {
            return null;
        }
        $stance = (string) ($parsed['stance'] ?? DimaPrompt::OPEN);
        if (! in_array($stance, DimaPrompt::STANCES, true)) {
            $stance = DimaPrompt::OPEN;
        }
        $memory = DimaPrompt::memoryFrom($parsed['memory'] ?? null, DimaPrompt::memoryFrom($prior['memory'] ?? null));
        $memory['wants_to_continue'] = true;

        return [
            'text' => $text,
            'stance' => $stance,
            'note' => mb_substr(trim((string) ($parsed['note'] ?? '')), 0, 280),
            'memory' => $memory,
        ];
    }
}
