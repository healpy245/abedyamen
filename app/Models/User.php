<?php

namespace App\Models;

use App\Enums\AppDevelopmentRole;
use App\Enums\Door;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\Concerns\HasDoorAccess;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasDoorAccess, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'projects',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'projects' => 'array',
            'door_access' => 'array',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Whether this user may open the given project.
     *
     * Admins implicitly have access to the whole catalog, so they never need
     * their `projects` column kept in sync as new tools are added. What yamen
     * changes on the users page wins (see HasDoorAccess).
     */
    public function canAccessProject(Project|string $project): bool
    {
        $project = $project instanceof Project
            ? $project
            : Project::tryFromKey($project);

        if ($project === null) {
            return false;
        }

        return $this->canOpenDoor(Door::forProject($project));
    }

    /**
     * The one product this user sees in Customer Support. Null means every product.
     */
    public function supportProduct(): ?string
    {
        return strcasecmp((string) $this->email, 'moaz@kaman.rest') === 0 ? 'mall' : null;
    }

    /**
     * Live app connections only. Every version. No logs, tickets, commands,
     * or other workspace projects.
     */
    public function supportConnectionsOnly(): bool
    {
        return strcasecmp((string) $this->email, 'connections@kaman.rest') === 0;
    }

    /**
     * Support console is limited to the live board. Tickets and commands stay closed.
     */
    public function limitedSupportConsole(): bool
    {
        return $this->supportConnectionsOnly();
    }

    public function projectEntryUrl(Project $project): string
    {
        if ($project === Project::KamanSupport && $this->limitedSupportConsole()) {
            return route('kaman-support.live');
        }

        return $project->url();
    }

    /**
     * Every project this user may open, in catalog order.
     *
     * @return list<Project>
     */
    public function accessibleProjects(): array
    {
        return array_values(array_filter(
            Project::cases(),
            fn (Project $project): bool => $this->canAccessProject($project),
        ));
    }

    /**
     * The raw project keys granted to this user (ignoring admin status).
     *
     * @return list<string>
     */
    public function projectKeys(): array
    {
        $projects = $this->projects;

        if (!is_array($projects)) {
            return [];
        }

        return array_values(array_filter($projects, 'is_string'));
    }

    public function appDevelopmentMembership(): HasOne
    {
        return $this->hasOne(AppDevelopmentMember::class);
    }

    public function appDevelopmentRole(): ?AppDevelopmentRole
    {
        return $this->appDevelopmentMembership?->role;
    }

    public function isAppDevelopmentAdmin(): bool
    {
        return $this->is_admin
            || $this->appDevelopmentRole() === AppDevelopmentRole::Admin;
    }

    public function isQa(): bool
    {
        return $this->isAppDevelopmentAdmin()
            || $this->appDevelopmentRole() === AppDevelopmentRole::Qa;
    }

    public function isDeveloper(): bool
    {
        return $this->isAppDevelopmentAdmin()
            || $this->appDevelopmentRole() === AppDevelopmentRole::Developer;
    }

    /**
     * An account whose only grants belonged to the chatbot studio, which now lives in its own project.
     */
    public function isChatbotOnly(): bool
    {
        if ($this->is_admin) {
            return false;
        }

        $keys = $this->projectKeys();

        return $keys !== [] && array_intersect($keys, Project::keys()) === [];
    }

    public function afterLoginUrl(): string
    {
        if ($this->isChatbotOnly()) {
            return route('login');
        }

        if ($this->accessibleProjects() !== [] || filled($this->door_access)) {
            if ($this->canOpenSubDoor(Door::Overview, 'dashboard')) {
                return route('design.modern-light');
            }

            $door = $this->firstOpenDoor();

            return $door !== null ? $this->doorEntryUrl($door) : route('design.modern-light');
        }

        return route('login');
    }
}
