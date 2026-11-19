<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Door;
use App\Enums\Project;

/**
 * Who may open which door and sub door.
 *
 * `door_access` holds only what Yamen changed, as {"finance": true, "finance.clients": false}.
 * Anything not in it falls back to the standard access the account has always had, so an empty
 * column means nothing changed. WorkspaceUserSeeder resets `projects` but never touches this column.
 */
trait HasDoorAccess
{
    public const USER_MANAGER_EMAIL = 'yamen@kaman.rest';

    /** Super admins: every door, and the users page. */
    public const USER_MANAGER_EMAILS = ['yamen@kaman.rest', 'fregmac@kaman.rest'];

    /** Support tabs the live-only accounts can never open: their version scope only covers the live board. */
    private const LIMITED_SUPPORT_CLOSED = ['logs', 'menu', 'tickets'];

    public function managesUsers(): bool
    {
        return in_array(mb_strtolower(trim((string) $this->email)), self::USER_MANAGER_EMAILS, true);
    }

    /**
     * An agents admin sees and changes every agent's leads, meetings and quotes. A worker only their own.
     */
    public function managesAgents(): bool
    {
        return $this->canOpenSubDoor(Door::Agents, 'manage');
    }

    /**
     * A turned off account cannot sign in. Only signing in depends on this: what the account owns,
     * like its bots, keeps working. Yamen's account can never be turned off.
     */
    public function isTurnedOff(): bool
    {
        return $this->disabled_at !== null && ! $this->managesUsers();
    }

    public function canOpenDoor(Door $door): bool
    {
        if ($this->managesUsers()) {
            return true;
        }

        return $this->fixedDoorState($door)
            ?? $this->doorOverride($door->value)
            ?? $this->standardDoorAccess($door);
    }

    public function canOpenSubDoor(Door $door, string $subDoor): bool
    {
        return $door->hasSubDoor($subDoor)
            && $this->canOpenDoor($door)
            && $this->subDoorSetting($door, $subDoor);
    }

    /**
     * Where a sub door's switch stands, whether or not its door is open right now.
     */
    public function subDoorSetting(Door $door, string $subDoor): bool
    {
        if ($this->managesUsers()) {
            return true;
        }

        return $this->fixedSubDoorState($door, $subDoor)
            ?? $this->doorOverride($door->value.'.'.$subDoor)
            ?? $this->standardSubDoorAccess($door, $subDoor);
    }

    /**
     * A null sub door asks about the door itself.
     */
    public function canOpen(Door $door, ?string $subDoor = null): bool
    {
        return $subDoor === null ? $this->canOpenDoor($door) : $this->canOpenSubDoor($door, $subDoor);
    }

    /**
     * Where the door takes this user: the first sub door they can open, or its first tab to show the lock.
     */
    public function doorEntryUrl(Door $door): string
    {
        foreach ($door->subDoors() as $key => $sub) {
            if ($sub['route'] !== null && $this->canOpenSubDoor($door, $key)) {
                return $door->url($key);
            }
        }

        return $door->url();
    }

    /**
     * The first door this user can get into, for the locked page's way out and for signing in.
     */
    public function firstOpenDoor(): ?Door
    {
        foreach (Door::cases() as $door) {
            if (! $this->canOpenDoor($door)) {
                continue;
            }
            if ($door->subDoors() === []) {
                return $door;
            }
            foreach ($door->subDoors() as $key => $sub) {
                if ($sub['route'] !== null && $this->canOpenSubDoor($door, $key)) {
                    return $door;
                }
            }
        }

        return null;
    }

    /**
     * Whether the restaurant modal may show its Finance or Support section. Its own switch decides,
     * and taking the whole door away from someone who had it takes this section with it. With the
     * Overview door closed the modal still opens from the doors this user has, and only those sections
     * show: the others are locked.
     */
    public function canSeeRestaurantSection(Door $door): bool
    {
        if ($this->managesUsers()) {
            return true;
        }

        $switch = $door === Door::Finance ? 'card_finance' : 'card_support';

        return $this->subDoorSetting(Door::Overview, $switch)
            && $this->doorOverride($door->value) !== false
            && ($this->canOpenDoor(Door::Overview) || $this->canOpenDoor($door));
    }

    /**
     * The state of a door yamen cannot change for this account, or null when it can change: yamen
     * always has everything, and Mowahada and the chatbot-only accounts stay out of the workspace.
     */
    public function fixedDoorState(Door $door): ?bool
    {
        if ($this->managesUsers()) {
            return true;
        }
        if ($this->isChatbotOnly()) {
            return false;
        }

        return null;
    }

    public function fixedSubDoorState(Door $door, string $subDoor): ?bool
    {
        if ($this->managesUsers()) {
            return true;
        }
        if ($door === Door::Support && $this->limitedSupportConsole() && in_array($subDoor, self::LIMITED_SUPPORT_CLOSED, true)) {
            return false;
        }

        return null;
    }

    /**
     * Turns a door or sub door on or off. Going back to the standard access clears the entry, so the
     * column only ever lists real changes.
     */
    public function setDoorAccess(Door $door, ?string $subDoor, bool $on): void
    {
        $standard = $subDoor === null ? $this->standardDoorAccess($door) : $this->standardSubDoorAccess($door, $subDoor);
        $key = $subDoor === null ? $door->value : $door->value.'.'.$subDoor;
        $map = is_array($this->door_access) ? $this->door_access : [];

        if ($on === $standard) {
            unset($map[$key]);
        } else {
            $map[$key] = $on;
        }

        $this->forceFill(['door_access' => $map === [] ? null : $map])->save();
    }

    public function standardDoorAccess(Door $door): bool
    {
        if ($door->project() !== null) {
            return $this->hasStandardProjectAccess($door->project());
        }

        return match ($door) {
            Door::Finance => (bool) $this->is_admin,
            default => ! $this->isChatbotOnly(),
        };
    }

    public function standardSubDoorAccess(Door $door, string $subDoor): bool
    {
        return match (true) {
            $door === Door::Development && $subDoor === 'reports' => (bool) $this->is_admin,
            $door === Door::Agents && $subDoor === 'manage' => (bool) $this->is_admin,
            default => true,
        };
    }

    public function hasStandardProjectAccess(Project $project): bool
    {
        if ($project->requiresExplicitGrant()) {
            return in_array($project->value, $this->projectKeys(), true);
        }

        return $this->is_admin || in_array($project->value, $this->projectKeys(), true);
    }

    private function doorOverride(string $key): ?bool
    {
        $map = $this->door_access;

        return is_array($map) && is_bool($map[$key] ?? null) ? $map[$key] : null;
    }
}
