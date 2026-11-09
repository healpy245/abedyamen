<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\Finance\HardwareStatus;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\ServiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class FinanceClient extends Model
{
    public const LANGUAGES = ['en', 'ar', 'he'];

    public const CARD_BRANDS = ['visa', 'mastercard', 'amex', 'isracard', 'diners', 'card'];

    protected $hidden = ['tranzila_token'];

    protected $fillable = [
        'company_id',
        'mfit_client_id',
        'name',
        'contact_name',
        'business_number',
        'subdomain',
        'logo',
        'phone',
        'lang',
        'service_status',
        'payment_status',
        'has_payment_method',
        'card_brand',
        'card_last4',
        'card_expiry',
        'card_holder',
        'hardware_status',
        'monthly_fee',
        'next_billing_date',
        'billing_items',
        'notes',
        'failed_notice_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_status' => ServiceStatus::class,
            'payment_status' => PaymentStatus::class,
            'hardware_status' => HardwareStatus::class,
            'has_payment_method' => 'boolean',
            'tranzila_token' => 'encrypted',
            'monthly_fee' => 'decimal:2',
            'next_billing_date' => 'date',
            'billing_items' => 'array',
            'failed_notice_at' => 'datetime',
        ];
    }

    /**
     * The restaurant holding the MFITGROUP account this one is billed under, when it shares one.
     */
    public function billingClient(): BelongsTo
    {
        return $this->belongsTo(self::class, 'billing_client_id');
    }

    /**
     * Restaurants billed under this one's account.
     */
    public function linkedClients(): HasMany
    {
        return $this->hasMany(self::class, 'billing_client_id')->orderBy('name');
    }

    /**
     * The finance account this restaurant belongs to: itself, or the restaurant it is linked to.
     */
    public function account(): self
    {
        return $this->billing_client_id !== null ? ($this->billingClient ?? $this) : $this;
    }

    /**
     * Every restaurant in the account, the account holder first.
     *
     * @return Collection<int, self>
     */
    public function accountRestaurants(): Collection
    {
        $account = $this->account();

        return collect([$account])->concat($account->linkedClients);
    }

    /**
     * What the whole account is billed every month: each restaurant's features, named by restaurant
     * when the account has more than one.
     *
     * @return list<array{name: string, amount: float}>
     */
    public function accountBillingLines(): array
    {
        $restaurants = $this->accountRestaurants();
        if ($restaurants->count() === 1) {
            return $this->account()->billingLines();
        }

        return $restaurants->flatMap(fn (self $restaurant): array => array_map(
            fn (array $line): array => ['name' => $restaurant->name.' · '.$line['name'], 'amount' => $line['amount']],
            $restaurant->billingLines(),
        ))->values()->all();
    }

    public function accountMonthlyFee(): float
    {
        return round(array_sum(array_column($this->accountBillingLines(), 'amount')), 2);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class)->orderByDesc('period')->orderByDesc('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FinanceInvoice::class)->orderByDesc('issued_on')->orderByDesc('id');
    }

    public function tranzilaTransactions(): HasMany
    {
        return $this->hasMany(TranzilaTransaction::class)->orderByDesc('id');
    }

    public function tranzilaDocuments(): HasMany
    {
        return $this->hasMany(TranzilaDocument::class)->orderByDesc('id');
    }

    public function tranzilaSubscriptions(): HasMany
    {
        return $this->hasMany(TranzilaSubscription::class)->orderByDesc('id');
    }

    public function salesQuotes(): HasMany
    {
        return $this->hasMany(\App\Models\Sales\SalesQuote::class);
    }

    /**
     * Sold deals whose devices are not fully paid yet.
     */
    public function devicesOwed(): HasMany
    {
        return $this->salesQuotes()->where('status', 'sold')->where('devices_due', '>', 0)->orderBy('sold_at');
    }

    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim($this->name) ?: '?', 0, 1));
    }

    public function siteUrl(): ?string
    {
        return filled($this->subdomain) ? 'https://'.$this->subdomain.'.kaman.rest' : null;
    }

    /**
     * The client's page in the MFITGROUP admin, once the client is linked to it.
     */
    public function mfitUrl(): ?string
    {
        return $this->mfit_client_id ? rtrim((string) config('finance.mfit_client_url'), '/').'/'.$this->mfit_client_id : null;
    }

    /**
     * The features billed every month. Clients set up before itemised billing show their fee as one line.
     *
     * @return list<array{name: string, amount: float}>
     */
    public function billingLines(): array
    {
        $items = collect($this->billing_items ?? [])
            ->filter(fn ($row): bool => is_array($row) && filled($row['name'] ?? null))
            ->map(fn (array $row): array => ['name' => (string) $row['name'], 'amount' => round((float) ($row['amount'] ?? 0), 2)])
            ->values()
            ->all();

        if ($items === [] && (float) $this->monthly_fee > 0) {
            return [['name' => __('finance.billing.default_line'), 'amount' => (float) $this->monthly_fee]];
        }

        return $items;
    }
}
