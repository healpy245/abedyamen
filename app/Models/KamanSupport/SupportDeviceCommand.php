<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use App\Enums\KamanSupport\SupportCommandName;
use App\Enums\KamanSupport\SupportCommandStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportDeviceCommand extends Model
{
    protected $table = 'support_device_commands';

    protected $fillable = [
        'support_device_id',
        'support_ticket_id',
        'public_id',
        'name',
        'args',
        'status',
        'result',
        'actor_name',
        'sent_at',
        'completed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => SupportCommandName::class,
            'status' => SupportCommandStatus::class,
            'args' => 'array',
            'result' => 'array',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<SupportDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SupportDevice::class, 'support_device_id');
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPosPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name instanceof SupportCommandName ? $this->name->value : (string) $this->name,
            'args' => $this->args ?? new \stdClass,
            'ticketId' => $this->ticket?->public_id,
        ];
    }
}
