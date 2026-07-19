<?php

namespace App\Models\AiChatbot;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotInstance extends Model
{
    use HasFactory;

    protected $table = 'ai_chatbot_instances';

    protected $fillable = [
        'user_id',
        'name',
        'system_prompt',
        'stores_members',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stores_members' => 'boolean',
        ];
    }

    public function storesMembers(): bool
    {
        return (bool) $this->stores_members;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatbotConversation::class, 'instance_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatbotMember::class, 'instance_id');
    }
}
