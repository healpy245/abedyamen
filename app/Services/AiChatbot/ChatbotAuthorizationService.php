<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use Illuminate\Support\Collection;

class ChatbotAuthorizationService
{
    public const ABILITY_VIEW = 'view';

    public const ABILITY_REPLY = 'reply';

    public const ABILITY_CONTROL_BOT = 'control_bot';

    public const ABILITY_MANAGE_INSTRUCTIONS = 'manage_instructions';

    public const ABILITY_MANAGE_SETTINGS = 'manage_settings';

    public const ABILITY_RUN_TESTS = 'run_tests';

    public const ABILITY_MANAGE_INTEGRATION = 'manage_integration';

    public const ABILITY_MANAGE_TEAM = 'manage_team';

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_ABILITIES = [
        ChatbotInstanceUser::ROLE_OWNER => [
            self::ABILITY_VIEW,
            self::ABILITY_REPLY,
            self::ABILITY_CONTROL_BOT,
            self::ABILITY_MANAGE_INSTRUCTIONS,
            self::ABILITY_MANAGE_SETTINGS,
            self::ABILITY_RUN_TESTS,
            self::ABILITY_MANAGE_INTEGRATION,
            self::ABILITY_MANAGE_TEAM,
        ],
        ChatbotInstanceUser::ROLE_MANAGER => [
            self::ABILITY_VIEW,
            self::ABILITY_REPLY,
            self::ABILITY_CONTROL_BOT,
            self::ABILITY_MANAGE_INSTRUCTIONS,
            self::ABILITY_MANAGE_SETTINGS,
            self::ABILITY_RUN_TESTS,
            self::ABILITY_MANAGE_TEAM,
        ],
        ChatbotInstanceUser::ROLE_AGENT => [
            self::ABILITY_VIEW,
            self::ABILITY_REPLY,
            self::ABILITY_CONTROL_BOT,
            self::ABILITY_MANAGE_INSTRUCTIONS,
        ],
        ChatbotInstanceUser::ROLE_VIEWER => [
            self::ABILITY_VIEW,
        ],
    ];

    public function canAccessInstance(User $user, ChatbotInstance $instance): bool
    {
        if ($user->is_admin ?? false) {
            return true;
        }

        if ((int) $instance->user_id === (int) $user->id) {
            return true;
        }

        return $this->membership($instance, $user) !== null;
    }

    public function authorize(User $user, ChatbotInstance $instance, string $ability): void
    {
        if (! $this->can($user, $instance, $ability)) {
            abort(403);
        }
    }

    public function authorizeOrFail(User $user, ChatbotInstance $instance, string $ability): void
    {
        $this->authorize($user, $instance, $ability);
    }

    public function can(User $user, ChatbotInstance $instance, string $ability): bool
    {
        if (! $this->canAccessInstance($user, $instance)) {
            return false;
        }

        if ($user->is_admin ?? false) {
            return true;
        }

        $role = $this->resolveRole($user, $instance);
        $abilities = self::ROLE_ABILITIES[$role] ?? [self::ABILITY_VIEW];

        $membership = $this->membership($instance, $user);
        $overrides = is_array($membership?->permissions) ? $membership->permissions : [];

        if (isset($overrides[$ability])) {
            return (bool) $overrides[$ability];
        }

        return in_array($ability, $abilities, true);
    }

    public function resolveRole(User $user, ChatbotInstance $instance): string
    {
        if ($user->is_admin ?? false) {
            return ChatbotInstanceUser::ROLE_OWNER;
        }

        if ((int) $instance->user_id === (int) $user->id) {
            return ChatbotInstanceUser::ROLE_OWNER;
        }

        $membership = $this->membership($instance, $user);

        return $membership?->role ?? ChatbotInstanceUser::ROLE_VIEWER;
    }

    public function membership(ChatbotInstance $instance, User $user): ?ChatbotInstanceUser
    {
        return ChatbotInstanceUser::query()
            ->where('instance_id', $instance->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Instances the user may open (owner, granted access, or all for admins).
     *
     * @return Collection<int, ChatbotInstance>
     */
    public function instancesForUser(User $user): Collection
    {
        if ($user->is_admin ?? false) {
            return ChatbotInstance::query()->orderBy('name')->get();
        }

        $grantedIds = ChatbotInstanceUser::query()
            ->where('user_id', $user->id)
            ->pluck('instance_id');

        return ChatbotInstance::query()
            ->where(function ($q) use ($user, $grantedIds): void {
                $q->where('user_id', $user->id);
                if ($grantedIds->isNotEmpty()) {
                    $q->orWhereIn('id', $grantedIds);
                }
            })
            ->orderBy('name')
            ->get();
    }

    public function firstAccessibleForUser(User $user): ?ChatbotInstance
    {
        return $this->instancesForUser($user)->first();
    }

    public function grantAccess(
        ChatbotInstance $instance,
        User $user,
        string $role = ChatbotInstanceUser::ROLE_AGENT,
        ?array $permissions = null,
    ): ChatbotInstanceUser {
        return ChatbotInstanceUser::query()->updateOrCreate(
            [
                'instance_id' => $instance->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role,
                'permissions' => $permissions,
            ],
        );
    }
}
