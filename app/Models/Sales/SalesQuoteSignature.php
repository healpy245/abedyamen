<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One contract link sent to the client: pending until the client signs, then signed with the drawn
 * signature and a snapshot of the contract as it was signed. Sending again cancels it.
 */
class SalesQuoteSignature extends Model
{
    public const DISK = 'local';

    public const PENDING = 'pending';

    public const SIGNED = 'signed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'sales_quote_id',
        'token',
        'status',
        'phone',
        'sent_by',
        'signer_name',
        'signer_business_number',
        'signer_address',
        'signature_path',
        'snapshot',
        'signed_at',
        'signed_ip',
        'signed_user_agent',
        'cancelled_at',
        'cancelled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'signed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'sales_quote_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isSigned(): bool
    {
        return $this->status === self::SIGNED;
    }

    /**
     * The public link the client opens to read and sign the contract.
     */
    public function url(): string
    {
        return route('contracts.show', $this->token);
    }

    /**
     * The drawn signature as a data URI, so the contract page and its PDF need no extra request.
     */
    public function signatureDataUri(): ?string
    {
        if ($this->signature_path === null) {
            return null;
        }
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($this->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) $disk->get($this->signature_path));
    }
}
