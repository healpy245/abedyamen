<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLeadNote extends Model
{
    protected $fillable = [
        'agent_lead_id',
        'user_id',
        'text',
        'created_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
