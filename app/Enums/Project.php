<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The workspace project catalog.
 *
 * This enum is the single source of truth for which tools live in the
 * workspace. Route gating (`project:<value>` middleware), the user access
 * matrix (`users.projects`), and the welcome page cards all derive from it,
 * so adding a tool here is the only step needed to fold it into the system.
 */
enum Project: string
{
    case Form = 'form';
    case AiChatbot = 'ai-chatbot';

    /**
     * Human readable name, shown on the welcome page and in 403 messages.
     */
    public function label(): string
    {
        return __('projects.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('projects.'.$this->value.'.description');
    }

    /**
     * The landing route a user is sent to when they open the project.
     */
    public function routeName(): string
    {
        return match ($this) {
            self::Form => 'form.index',
            self::AiChatbot => 'ai-chatbot.index',
        };
    }

    public function url(): string
    {
        return route($this->routeName());
    }

    /**
     * Heroicon name for the workspace dashboard card.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Form => 'clipboard-document-list',
            self::AiChatbot => 'sparkles',
        };
    }

    /**
     * Light, functional accent classes for dashboard cards.
     *
     * @return array{
     *     icon_bg: string,
     *     icon_text: string,
     *     border_hover: string,
     *     status_bg: string,
     *     status_text: string,
     *     status_border: string
     * }
     */
    public function tone(): array
    {
        return match ($this) {
            self::Form => [
                'icon_bg' => 'bg-orange-50',
                'icon_text' => 'text-orange-600',
                'border_hover' => 'hover:border-orange-200',
                'status_bg' => 'bg-emerald-50',
                'status_text' => 'text-emerald-700',
                'status_border' => 'border-emerald-200',
            ],
            self::AiChatbot => [
                'icon_bg' => 'bg-violet-50',
                'icon_text' => 'text-violet-600',
                'border_hover' => 'hover:border-violet-200',
                'status_bg' => 'bg-emerald-50',
                'status_text' => 'text-emerald-700',
                'status_border' => 'border-emerald-200',
            ],
        };
    }

    /**
     * Secondary detail shown on hover via the native title tooltip.
     */
    public function detail(): string
    {
        return __('projects.'.$this->value.'.detail');
    }

    /**
     * Resolve a project from its string key, returning null when unknown.
     */
    public static function tryFromKey(string $key): ?self
    {
        return self::tryFrom($key);
    }

    /**
     * Every project key in the catalog.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $project): string => $project->value, self::cases());
    }
}
