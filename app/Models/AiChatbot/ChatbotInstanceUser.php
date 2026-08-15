<?php

namespace App\Models\AiChatbot;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotInstanceUser extends Model
{
    protected $table = 'ai_chatbot_instance_user';

    public const ROLE_OWNER = 'owner';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_AGENT = 'agent';

    public const ROLE_VIEWER = 'viewer';

    protected $fillable = [
        'instance_id',
        'user_id',
        'role',
        'permissions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'instance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
