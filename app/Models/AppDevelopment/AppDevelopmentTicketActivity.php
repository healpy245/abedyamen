<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Enums\AppDevelopmentTicketActivityType;
use App\Enums\AppDevelopmentTicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentTicketActivity extends Model
{
    public $timestamps = false;

    protected $table = 'app_development_ticket_activities';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'event_type',
        'from_status',
        'to_status',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => AppDevelopmentTicketActivityType::class,
            'from_status' => AppDevelopmentTicketStatus::class,
            'to_status' => AppDevelopmentTicketStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function note(): ?string
    {
        $metadata = $this->metadata;

        if (! is_array($metadata)) {
            return null;
        }

        $note = $metadata['note'] ?? null;

        return is_string($note) && $note !== '' ? $note : null;
    }

    public function message(): string
    {
        $user = $this->user?->name ?? __('app-development.system');
        $type = $this->event_type instanceof AppDevelopmentTicketActivityType
            ? $this->event_type
            : AppDevelopmentTicketActivityType::tryFrom((string) $this->event_type);

        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return match ($type) {
            AppDevelopmentTicketActivityType::Created => __('app-development.activity.created', ['user' => $user]),
            AppDevelopmentTicketActivityType::Updated => __('app-development.activity.updated', ['user' => $user]),
            AppDevelopmentTicketActivityType::Assigned => __('app-development.activity.assigned', ['user' => $user]),
            AppDevelopmentTicketActivityType::StartedWork => __('app-development.activity.started_work', ['user' => $user]),
            AppDevelopmentTicketActivityType::Commented => __('app-development.activity.commented', ['user' => $user]),
            AppDevelopmentTicketActivityType::AttachmentAdded => __('app-development.activity.attachment_added', [
                'user' => $user,
                'file' => $metadata['original_name'] ?? '',
            ]),
            AppDevelopmentTicketActivityType::SentToQa => __('app-development.activity.sent_to_qa', ['user' => $user]),
            AppDevelopmentTicketActivityType::QaRejected => __('app-development.activity.qa_rejected', ['user' => $user]),
            AppDevelopmentTicketActivityType::Completed => __('app-development.activity.completed', ['user' => $user]),
            AppDevelopmentTicketActivityType::ApkLinked => __('app-development.activity.apk_linked', [
                'user' => $user,
                'version' => $metadata['version_name'] ?? '',
            ]),
            default => __('app-development.activity.updated', ['user' => $user]),
        };
    }
}
