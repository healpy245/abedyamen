<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentMember extends Model
{
    protected $table = 'app_development_members';

    protected $fillable = [
        'user_id',
        'role',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AppDevelopmentRole::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isQa(): bool
    {
        return $this->role === AppDevelopmentRole::Qa;
    }

    public function isDeveloper(): bool
    {
        return $this->role === AppDevelopmentRole::Developer;
    }
}
