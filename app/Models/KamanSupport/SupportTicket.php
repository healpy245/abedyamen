<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use App\Enums\KamanSupport\SupportTicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = [
        'public_id',
        'restaurant',
        'restaurant_name',
        'cashier_name',
        'device_id',
        'locale',
        'summary',
        'details',
        'conversation',
        'diagnosis',
        'printers',
        'extra',
        'status',
        'assignee',
        'client_created_at',
        'closed_at',
        'closed_by',
        'close_note',
        'escalated_ticket_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversation' => 'array',
            'diagnosis' => 'array',
            'printers' => 'array',
            'extra' => 'array',
            'status' => SupportTicketStatus::class,
            'client_created_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closeDurationLabel(): ?string
    {
        if ($this->closed_at === null || $this->created_at === null) {
            return null;
        }

        $minutes = (int) floor($this->created_at->diffInSeconds($this->closed_at) / 60);
        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours < 48) {
            return $hours.'h '.$rest.'m';
        }

        return intdiv($hours, 24).'d '.($hours % 24).'h';
    }

    public function reportLevel(): ?string
    {
        $level = is_array($this->extra) ? ($this->extra['level'] ?? null) : null;

        return in_array($level, ['danger_now', 'issue', 'question'], true) ? $level : null;
    }

    /**
     * The mobile number the restaurant left in the ticket, with its WhatsApp link.
     *
     * @return array{phone: string, whatsapp: string}|null
     */
    public function contact(): ?array
    {
        return \App\Support\WhatsAppContact::find($this->details, $this->summary);
    }

    /**
     * What the cashier wrote, once. The POS puts the callback line first and "voice 30s" / "screen 12s"
     * last in the details, and repeats it all in the summary behind the level; those are shown elsewhere.
     */
    public function report(): string
    {
        $lines = preg_split('/\R/u', (string) $this->details) ?: [];
        $kept = array_filter($lines, static function (string $line): bool {
            $line = trim($line);

            return $line !== ''
                && preg_match('/^(voice|screen)\s+\d+\s*s$/iu', $line) !== 1
                && preg_match('/^(للتواصل|contact|ליצירת קשר)\s*:/iu', $line) !== 1;
        });
        $text = trim(implode("\n", $kept));
        if ($text !== '' || filled($this->details)) {
            return $text;
        }

        // No details: the summary, without the "Danger now:" level and the callback line.
        $summary = trim((string) $this->summary);
        $level = $this->reportLevel();
        if ($level !== null) {
            foreach (['en', 'ar', 'he'] as $locale) {
                $label = (string) __('kaman-support.level.'.$level, [], $locale);
                if ($label !== '' && str_starts_with($summary, $label.':')) {
                    $summary = trim(mb_substr($summary, mb_strlen($label) + 1));
                    break;
                }
            }
        }

        return trim((string) preg_replace('/^(للتواصل|contact|ליצירת קשר)\s*:\s*\+?[\d\s()\-]{7,}/iu', '', $summary));
    }

    /** Seconds of the voice or screen recording the POS reported, or null. */
    public function mediaSeconds(string $kind): ?int
    {
        $seconds = is_array($this->extra) ? ($this->extra[$kind.'Seconds'] ?? null) : null;
        if (is_numeric($seconds)) {
            return max(0, (int) $seconds);
        }
        if (preg_match('/^'.preg_quote($kind, '/').'\s+(\d+)\s*s$/imu', (string) $this->details, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Everything development needs, as text for the escalated ticket: what the cashier said, who and
     * where, how to call back, the recordings and a link back to this ticket.
     */
    public function escalationText(): string
    {
        $timezone = (string) config('kaman-support.display_timezone', 'Asia/Jerusalem');
        $contact = $this->contact();
        $level = $this->reportLevel();
        $voice = $this->hasVoice() ? $this->mediaSeconds('voice') : null;
        $screen = $this->hasScreen() ? $this->mediaSeconds('screen') : null;
        $report = $this->report();

        $facts = array_filter([
            __('kaman-support.restaurant') => trim(($this->restaurant_name ?: $this->restaurant).($this->restaurant_name && $this->restaurant ? ' ('.$this->restaurant.')' : '')),
            __('kaman-support.level_label') => $level ? __('kaman-support.level.'.$level) : null,
            __('kaman-support.contact') => $contact ? $contact['phone'].' · '.$contact['whatsapp'] : null,
            __('kaman-support.cashier') => $this->cashier_name,
            __('kaman-support.device') => $this->device_id,
            __('kaman-support.app_version') => self::scalar(is_array($this->extra) ? ($this->extra['appVersion'] ?? null) : null),
            __('kaman-support.received') => ($this->client_created_at ?? $this->created_at)?->timezone($timezone)->format('Y-m-d H:i'),
            __('kaman-support.voice') => $this->hasVoice() ? __('kaman-support.voice_attached', ['seconds' => $voice ?? '?']) : null,
            __('kaman-support.screen') => $this->hasScreen() ? __('kaman-support.screen_on_ticket', ['seconds' => $screen ?? '?']) : null,
            __('kaman-support.diagnosis') => self::scalar(is_array($this->diagnosis) ? ($this->diagnosis['cause'] ?? null) : null),
            __('kaman-support.support_ticket') => $this->public_id.' · '.route('kaman-support.inbox', ['ticket' => $this->public_id]),
        ], static fn ($value): bool => filled($value));

        $lines = [];
        $lines[] = $report !== '' ? $report : __('kaman-support.no_written_report');
        $lines[] = '';
        foreach ($facts as $label => $value) {
            $lines[] = $label.': '.$value;
        }

        $conversation = array_values(array_filter(array_map(
            static fn ($line): string => is_scalar($line) ? trim((string) $line) : '',
            is_array($this->conversation) ? $this->conversation : [],
        )));
        if ($conversation !== []) {
            $lines[] = '';
            $lines[] = __('kaman-support.conversation').':';
            array_push($lines, ...$conversation);
        }

        return implode("\n", $lines);
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    public function hasVoice(): bool
    {
        return $this->mediaPath('audioPath') !== null;
    }

    public function hasScreen(): bool
    {
        return $this->mediaPath('screenPath') !== null;
    }

    public function mediaPath(string $key): ?string
    {
        $path = is_array($this->extra) ? ($this->extra[$key] ?? null) : null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        if (str_contains($path, '..') || ! str_starts_with($path, 'kaman-support/')) {
            return null;
        }

        return $path;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        return $this->where($field, $value)->orderByDesc('id')->first();
    }

    /**
     * @return HasMany<SupportTicketEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class)->orderBy('id');
    }

    /**
     * @return HasOne<SupportDevice, $this>
     */
    public function device(): HasOne
    {
        return $this->hasOne(SupportDevice::class, 'device_id', 'device_id')
            ->whereColumn('support_devices.restaurant', 'support_tickets.restaurant');
    }

    /**
     * A user limited to one product sees only the tickets sent from that product's devices.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $user?->supportProduct() === null
            ? $query
            : $query->whereHas('device', fn (Builder $device) => $device->visibleTo($user));
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('summary', 'like', $like)
                ->orWhere('details', 'like', $like)
                ->orWhere('public_id', 'like', $like)
                ->orWhere('restaurant', 'like', $like)
                ->orWhere('restaurant_name', 'like', $like)
                ->orWhere('cashier_name', 'like', $like);
        });
    }
}
