<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Services\AiChatbot\DimaV3\ConversationEngine;
use App\Services\AiChatbot\DimaV3\ConversationState;

/**
 * Switches a Dima turn between V1, V2, and V3.
 * The default stays on V1 until DIMA_ENGINE is set.
 */
class DimaEngine
{
    public static function usesV2(): bool
    {
        return config('dima.engine') === 'v2';
    }

    public static function usesV3(): bool
    {
        return config('dima.engine') === 'v3';
    }

    /**
     * Live WhatsApp sending for Dima: openings, replies, clips, nudges.
     * Off unless DIMA_OUTBOUND=true, so it can be switched off without a deploy.
     */
    public static function allowsOutbound(): bool
    {
        return (bool) config('dima.outbound', false);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:mixed,memory:array<string, mixed>}
     */
    public static function nextReply(array $turns, ?string $name, array $prior = [], bool $live = false): array
    {
        if (self::usesV3()) {
            return app(ConversationEngine::class)->turn($turns, $name, $prior, $live);
        }
        if (self::usesV2()) {
            return app(DimaConversationService::class)->turn($turns, $name, $prior, $live);
        }

        return DimaPrompt::nextReply($turns, $name, $prior, $live);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public static function nudge(array $turns, ?string $name, bool $live = false): string
    {
        if (self::usesV3()) {
            return '';
        }
        if (self::usesV2()) {
            return app(DimaConversationService::class)->nudge($turns, $name, $live);
        }

        return DimaPrompt::nudge($turns, $name, $live);
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(ChatbotConversation $conversation): array
    {
        if (self::usesV3()) {
            return app(ConversationState::class)->read($conversation);
        }

        return DimaPrompt::read($conversation);
    }

    /**
     * @param  array<string, mixed>  $memory
     * @param  array<string, mixed>|null  $identity
     */
    public static function remember(ChatbotConversation $conversation, string $stance, string $note, bool $close, array $memory = [], ?string $stage = null, ?array $identity = null): void
    {
        if (self::usesV3()) {
            if ($close) {
                $memory['opted_out'] = true;
                $memory['current_stage'] = 'closed';
            } elseif (is_string($stage) && $stage !== '' && ! isset($memory['current_stage'])) {
                $memory['current_stage'] = $stage;
            }
            app(ConversationState::class)->write($conversation, $memory);

            return;
        }

        DimaPrompt::remember($conversation, $stance, $note, $close, $memory, $stage, $identity);
    }

    public static function isClosed(ChatbotConversation $conversation): bool
    {
        if (self::usesV3()) {
            return app(ConversationState::class)->isClosed($conversation);
        }

        return DimaPrompt::isClosed($conversation);
    }

    public static function openingFor(?string $name): string
    {
        if (self::usesV3()) {
            return ConversationEngine::openingFor($name);
        }

        return DimaPrompt::openingFor($name);
    }
}
