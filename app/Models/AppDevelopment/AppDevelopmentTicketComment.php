<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentTicketComment extends Model
{
    protected $table = 'app_development_ticket_comments';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
        'attachment_id',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTicketAttachment::class, 'attachment_id');
    }
}
