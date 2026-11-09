<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Enums\Sales\LeadStage;
use App\Models\Finance\FinanceClient;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentLead extends Model
{
    /** The column's default, so a row just created reads the same as one loaded later. */
    protected $attributes = [
        'stage' => 'new',
    ];

    protected $fillable = [
        'name',
        'contact',
        'phone',
        'city_id',
        'restaurant_id',
        'agent_id',
        'stage',
        'next_follow_up_at',
        'created_by',
        'import_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => LeadStage::class,
            'next_follow_up_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The client this lead became: restaurant_id is the kaman.rest company the client is kept under.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(FinanceClient::class, 'restaurant_id', 'company_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(SalesQuote::class);
    }

    /**
     * Client boarding, with the lead's details in the link for the site to fill its first step.
     */
    public function boardingUrl(string $lang): string
    {
        $contact = trim((string) $this->contact);
        [$first, $last] = array_pad(preg_split('/\s+/u', $contact, 2) ?: [], 2, '');

        $query = http_build_query(array_filter([
            'firstName' => $first,
            'lastName' => $last,
            'phone' => trim((string) $this->phone),
            'restaurant' => $this->name,
        ]), '', '&', PHP_QUERY_RFC3986);

        return 'https://boarding.mfitgroup.com/'.(in_array($lang, ['he', 'ar', 'en'], true) ? $lang : 'he').($query !== '' ? '?'.$query : '');
    }

    /**
     * Newest first, as a lead's notes are shown.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(AgentLeadNote::class)->orderByDesc('created_at')->orderByDesc('id');
    }
}
