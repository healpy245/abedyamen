<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Enums\Sales\AppointmentStatus;
use App\Enums\Sales\AppointmentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentAppointment extends Model
{
    /** The columns' defaults, so a row just created reads the same as one loaded later. */
    protected $attributes = [
        'type' => 'visit',
        'status' => 'scheduled',
    ];

    protected $fillable = [
        'agent_lead_id',
        'restaurant_id',
        'restaurant_name',
        'agent_id',
        'at',
        'type',
        'status',
        'notes',
        'created_by',
        'import_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'type' => AppointmentType::class,
            'status' => AppointmentStatus::class,
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(AgentLead::class, 'agent_lead_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
