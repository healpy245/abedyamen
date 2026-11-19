<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Enums\Sales\QuoteStatus;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinanceInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesQuote extends Model
{
    public const LANGUAGES = ['he', 'ar'];

    /** Languages the quote document can be downloaded in. */
    public const DOCUMENT_LANGUAGES = ['ar', 'he', 'en'];

    /** Numbering continues from the old Kaman Agents system. */
    public const FIRST_NUMBER = 1001;

    protected $fillable = [
        'number',
        'finance_client_id',
        'agent_lead_id',
        'contact_name',
        'lang',
        'status',
        'agent_id',
        'monthly_total',
        'one_time_total',
        'devices_total',
        'devices_due',
        'notes',
        'whatsapp_sent_at',
        'deal_note',
        'requested_at',
        'finance_note',
        'confirmed_by',
        'sold_at',
        'synced_at',
        'finance_invoice_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'monthly_total' => 'decimal:2',
            'one_time_total' => 'decimal:2',
            'devices_total' => 'decimal:2',
            'devices_due' => 'decimal:2',
            'whatsapp_sent_at' => 'datetime',
            'requested_at' => 'datetime',
            'sold_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(FinanceClient::class, 'finance_client_id');
    }

    /**
     * Set only until the lead is converted; from then on the quote is the client's.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(AgentLead::class, 'agent_lead_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'finance_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesQuoteItem::class)->orderBy('sort')->orderBy('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SalesQuoteFile::class)->orderBy('id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(SalesQuoteSignature::class)->latest('id');
    }

    /**
     * The contract link still in force: waiting for the client, or signed. Cancelled links are history.
     */
    public function signature(): HasOne
    {
        return $this->hasOne(SalesQuoteSignature::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('status', '!=', SalesQuoteSignature::CANCELLED));
    }

    /**
     * The client signed the contract and it was not sent again since.
     */
    public function isSigned(): bool
    {
        return $this->signature?->isSigned() ?? false;
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Who the quote is for: the client, or the lead until it is converted.
     */
    public function customerName(): string
    {
        return (string) ($this->client?->name ?? $this->lead?->name);
    }

    public function customerPhone(): string
    {
        return (string) ($this->client?->phone ?: $this->lead?->phone);
    }

    /**
     * Still for a lead that has no client yet, so the deal cannot go to Finance.
     */
    public function needsConversion(): bool
    {
        return $this->finance_client_id === null && $this->lead?->client === null;
    }

    public function isSold(): bool
    {
        return $this->status === QuoteStatus::Sold || $this->sold_at !== null;
    }

    /**
     * Waiting for Finance or already sold: the lines are with Finance, so the agent can no longer change them.
     */
    public function isLocked(): bool
    {
        return $this->isSold() || $this->status === QuoteStatus::Pending || $this->isSigned();
    }

    /**
     * Pending or sold: the signature is part of the deal Finance has, so it can no longer be cancelled.
     */
    public function isWithFinance(): bool
    {
        return $this->isSold() || $this->status === QuoteStatus::Pending;
    }

    /**
     * "Sale" once sold, "Quote" before that.
     */
    public function title(): string
    {
        return __($this->isSold() ? 'sales.sale_number' : 'sales.quote_number', ['number' => $this->number]);
    }
}
