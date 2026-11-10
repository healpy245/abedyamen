<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kitchen ticket that did not print on one kitchen printer, from the first
 * failure to the reprint (or the cashier skipping it).
 *
 * @property string $status open|resolved
 * @property string $fault see SupportPrinterIssue::FAULTS
 */
class SupportPrinterIssue extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    /** Fault codes the POS sends (`KitchenPrintFault` in the Flutter app). */
    public const FAULTS = [
        'no_printer',
        'paper_out',
        'cover_open',
        'printer_error',
        'busy',
        'cut_mid_print',
        'timeout',
        'refused',
        'unreachable',
        'network_down',
        'connection_reset',
        'unknown',
    ];

    protected $table = 'support_printer_issues';

    protected $fillable = [
        'support_device_id',
        'restaurant',
        'incident',
        'status',
        'fault',
        'fault_class',
        'may_have_printed',
        'station',
        'station_id',
        'printer_ip',
        'printer_port',
        'printer_mac',
        'printer_link',
        'printer_name',
        'order_id',
        'order_number',
        'order_table',
        'order_channel',
        'order_type',
        'external_order_id',
        'cashier_name',
        'app_version',
        'reason',
        'error_detail',
        'meals',
        'printed_on',
        'configured_printers',
        'attempts_log',
        'attempts',
        'dismissed_count',
        'first_failed_at',
        'last_failed_at',
        'dismissed_at',
        'resolved_at',
        'resolution',
        'resolved_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'may_have_printed' => 'boolean',
            'printer_port' => 'integer',
            'order_number' => 'integer',
            'meals' => 'array',
            'printed_on' => 'array',
            'configured_printers' => 'array',
            'attempts_log' => 'array',
            'attempts' => 'integer',
            'dismissed_count' => 'integer',
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SupportDevice::class, 'support_device_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function printerAddress(): string
    {
        $ip = trim((string) $this->printer_ip);
        if ($ip === '') {
            return '';
        }

        return $this->printer_port ? $ip.':'.$this->printer_port : $ip;
    }

    /**
     * Minutes from the first failure to the fix, or null while still open.
     */
    public function minutesToResolve(): ?int
    {
        if ($this->resolved_at === null || $this->first_failed_at === null) {
            return null;
        }

        return (int) max(0, round($this->first_failed_at->diffInSeconds($this->resolved_at) / 60));
    }
}
