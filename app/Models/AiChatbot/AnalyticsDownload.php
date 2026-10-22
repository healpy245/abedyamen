<?php

declare(strict_types=1);

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsDownload extends Model
{
    protected $table = 'analytics_downloads';

    protected $fillable = [
        'instance_id',
        'campaign_id',
        'user_id',
        'group',
        'line_key',
        'sequence',
        'row_count',
        'phones',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phones' => 'array',
            'payload' => 'array',
            'sequence' => 'integer',
            'row_count' => 'integer',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'instance_id');
    }
}
