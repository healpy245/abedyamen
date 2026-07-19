<?php

namespace App\Models;

use App\Enums\Project;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    /**
     * Whether this user may open the given project.
     *
     * Admins implicitly have access to the whole catalog, so they never need
     * their `projects` column kept in sync as new tools are added.
     */
    public function canAccessProject(Project|string $project): bool
    {
        $project = $project instanceof Project
            ? $project
            : Project::tryFromKey($project);

        if ($project === null) {
            return false;
        }

        if ($this->is_admin) {
            return true;
        }

        return in_array($project->value, $this->projectKeys(), true);
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
}
