<?php

declare(strict_types=1);

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Model;

class MowahadaLine extends Model
{
    protected $fillable = [
        'wa_instance',
        'url',
        'phone',
        'name',
        'status',
        'reason',
        'suspended_until',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'suspended_until' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'wa_instance';
    }
}
