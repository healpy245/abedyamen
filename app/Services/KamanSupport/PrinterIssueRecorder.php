<?php

declare(strict_types=1);

namespace App\Services\KamanSupport;

use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportDeviceLog;
use App\Models\KamanSupport\SupportPrinterIssue;
use Illuminate\Support\Carbon;

/**
 * Turns the POS kitchen-print logs into printer issues.
 *
 * The POS (Kaman Lite, `PrinterIssueLog`) writes `printer` logs whose context
 * `event` is one of:
 * - `kitchen_print_failed`: a kitchen ticket did not print on one kitchen.
 * - `kitchen_print_resolved`: it printed later, or the cashier skipped it.
 * - `kitchen_print_dismissed`: the cashier closed the alert without retrying.
 *
 * Every event carries an `incident` id that stays the same for one order on
 * one kitchen across retries, so one row follows the whole story. Only newly
 * stored logs reach this class, so a re-sent log never counts twice.
 */
class PrinterIssueRecorder
{
    private const ATTEMPT_LOG_MAX = 40;

    private const MEALS_MAX = 60;

    public function record(SupportDevice $device, SupportDeviceLog $log): ?SupportPrinterIssue
    {
        $context = is_array($log->context) ? $log->context : [];

        return match ((string) ($context['event'] ?? '')) {
            'kitchen_print_failed' => $this->failed($device, $log, $context),
            'kitchen_print_resolved' => $this->resolved($device, $log, $context),
            'kitchen_print_dismissed' => $this->dismissed($device, $log, $context),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failed(SupportDevice $device, SupportDeviceLog $log, array $context): ?SupportPrinterIssue
    {
        $incident = $this->text($context['incident'] ?? null, 191);
        if ($incident === null) {
            return null;
        }

        $at = $this->time($context, $log);
        $printer = is_array($context['printer'] ?? null) ? $context['printer'] : [];
        $order = is_array($context['order'] ?? null) ? $context['order'] : [];
        $fault = $this->fault($context['fault'] ?? null);
        $mayHavePrinted = filter_var($context['mayHavePrinted'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $issue = SupportPrinterIssue::query()->firstOrNew([
            'support_device_id' => $device->id,
            'incident' => $incident,
        ]);

        $reopened = $issue->exists && ! $issue->isOpen();
        if ($reopened) {
            // A new failure after a fix: same order and kitchen, new trouble.
            $issue->resolved_at = null;
            $issue->resolution = null;
            $issue->resolved_by = null;
            $issue->may_have_printed = false;
            $issue->printed_on = null;
            $issue->configured_printers = null;
        }

        $printedOn = $this->nameList($context, 'printedOn');
        $configured = $this->nameList($context, 'configuredPrinters');

        $issue->fill([
            'restaurant' => $this->text($device->restaurant, 120) ?? '_pending',
            'status' => SupportPrinterIssue::STATUS_OPEN,
            'fault' => $fault,
            'fault_class' => $this->text($context['class'] ?? null, 16),
            'may_have_printed' => (bool) $issue->may_have_printed || $mayHavePrinted,
            'station' => $this->text($context['station'] ?? null, 120) ?? $issue->station,
            'station_id' => $this->text($context['stationId'] ?? null, 120) ?? $issue->station_id,
            'printer_ip' => $this->text($printer['ip'] ?? null, 120) ?? $issue->printer_ip,
            'printer_port' => $this->port($printer['port'] ?? null) ?? $issue->printer_port,
            'printer_mac' => $this->text($printer['mac'] ?? null, 64) ?? $issue->printer_mac,
            'printer_link' => $this->text($printer['link'] ?? null, 32) ?? $issue->printer_link,
            'printer_name' => $this->text($printer['name'] ?? null, 120) ?? $issue->printer_name,
            'order_id' => $this->text($order['id'] ?? null, 120) ?? $issue->order_id,
            'order_number' => $this->port($order['number'] ?? null) ?? $issue->order_number,
            'order_table' => $this->text($order['table'] ?? null, 64) ?? $issue->order_table,
            'order_channel' => $this->text($order['channel'] ?? null, 32) ?? $issue->order_channel,
            'order_type' => $this->text($order['type'] ?? null, 32) ?? $issue->order_type,
            'external_order_id' => $this->text($order['external'] ?? null, 120) ?? $issue->external_order_id,
            'cashier_name' => $this->text($context['cashier'] ?? null, 120) ?? $device->cashier_name ?? $issue->cashier_name,
            'app_version' => $this->text($device->app_version, 64) ?? $issue->app_version,
            'reason' => $this->text($context['reason'] ?? null, 2000) ?? $this->text($log->message, 2000),
            'error_detail' => $this->text($context['error'] ?? null, 4000),
            'meals' => $this->meals($issue->meals ?? [], $context['meals'] ?? []),
            'printed_on' => $printedOn ?? $issue->printed_on,
            'configured_printers' => $configured ?? $issue->configured_printers,
            'attempts' => ((int) $issue->attempts) + 1,
            'first_failed_at' => $issue->first_failed_at ?? $at,
            'last_failed_at' => $issue->last_failed_at === null || $at->greaterThan($issue->last_failed_at)
                ? $at
                : $issue->last_failed_at,
        ]);

        $issue->attempts_log = $this->appendAttempt($issue->attempts_log ?? [], array_filter([
            'at' => $at->toIso8601String(),
            'event' => $reopened ? 'reopened' : 'failed',
            'attempt' => $this->text($context['attempt'] ?? null, 16),
            'fault' => $fault,
            'mayHavePrinted' => $mayHavePrinted,
            'reason' => $this->text($context['reason'] ?? null, 500),
            'error' => $this->text($context['error'] ?? null, 500),
            'printer' => $this->text($printer['ip'] ?? null, 120),
            'elapsedMs' => is_numeric($context['elapsedMs'] ?? null) ? (int) $context['elapsedMs'] : null,
            'printedOn' => is_array($context['printedOn'] ?? null) ? array_values($context['printedOn']) : null,
            'configuredPrinters' => is_array($context['configuredPrinters'] ?? null) ? array_values($context['configuredPrinters']) : null,
            'log' => $log->id,
        ], static fn ($value) => $value !== null && $value !== ''));

        $issue->save();

        return $issue;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolved(SupportDevice $device, SupportDeviceLog $log, array $context): ?SupportPrinterIssue
    {
        $incident = $this->text($context['incident'] ?? null, 191);
        if ($incident === null) {
            return null;
        }

        $issue = SupportPrinterIssue::query()
            ->where('support_device_id', $device->id)
            ->where('incident', $incident)
            ->first();
        if ($issue === null || ! $issue->isOpen()) {
            return $issue;
        }

        $at = $this->time($context, $log);
        // A fix logged before the latest failure belongs to an older attempt.
        if ($issue->last_failed_at !== null && $at->lessThan($issue->last_failed_at)) {
            return $issue;
        }

        $resolution = $this->text($context['resolution'] ?? null, 32) ?? 'printed';
        $issue->status = SupportPrinterIssue::STATUS_RESOLVED;
        $issue->resolved_at = $at;
        $issue->resolution = $resolution;
        $issue->resolved_by = $this->text($context['attempt'] ?? null, 32);
        $issue->attempts_log = $this->appendAttempt($issue->attempts_log ?? [], array_filter([
            'at' => $at->toIso8601String(),
            'event' => 'resolved',
            'resolution' => $resolution,
            'attempt' => $this->text($context['attempt'] ?? null, 16),
            'cashier' => $this->text($context['cashier'] ?? null, 120),
            'log' => $log->id,
        ], static fn ($value) => $value !== null && $value !== ''));
        $issue->save();

        return $issue;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dismissed(SupportDevice $device, SupportDeviceLog $log, array $context): ?SupportPrinterIssue
    {
        $at = $this->time($context, $log);
        $incidents = array_values(array_filter(
            array_map(fn ($value) => $this->text($value, 191), is_array($context['incidents'] ?? null) ? $context['incidents'] : []),
        ));
        $kitchens = array_values(array_filter(
            array_map(fn ($value) => $this->text($value, 120), is_array($context['kitchens'] ?? null) ? $context['kitchens'] : []),
        ));

        $query = SupportPrinterIssue::query()
            ->where('support_device_id', $device->id)
            ->where('status', SupportPrinterIssue::STATUS_OPEN);
        if ($incidents !== []) {
            $query->whereIn('incident', $incidents);
        } elseif ($kitchens !== []) {
            $query->whereIn('station', $kitchens);
        } else {
            return null;
        }

        $last = null;
        foreach ($query->get() as $issue) {
            $issue->dismissed_at = $at;
            $issue->dismissed_count = ((int) $issue->dismissed_count) + 1;
            $issue->attempts_log = $this->appendAttempt($issue->attempts_log ?? [], array_filter([
                'at' => $at->toIso8601String(),
                'event' => 'dismissed',
                'cashier' => $this->text($context['cashier'] ?? null, 120),
                'log' => $log->id,
            ], static fn ($value) => $value !== null && $value !== ''));
            $issue->save();
            $last = $issue;
        }

        return $last;
    }

    /**
     * @param  array<int, mixed>  $known
     * @return list<array<string, mixed>>
     */
    /**
     * A list the POS sent on this log, or null when the key is absent so an
     * older value is kept. An empty list is kept: nothing else printed.
     *
     * @param  array<string, mixed>  $context
     * @return list<string>|null
     */
    private function nameList(array $context, string $key): ?array
    {
        if (! array_key_exists($key, $context) || ! is_array($context[$key])) {
            return null;
        }

        $names = [];
        foreach ($context[$key] as $value) {
            $name = $this->text($value, 120);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return array_slice($names, 0, 40);
    }

    /**
     * @param  array<int, mixed>  $known
     * @return list<array<string, mixed>>
     */
    private function meals(array $known, mixed $incoming): array
    {
        $byLine = [];
        foreach ([$known, is_array($incoming) ? $incoming : []] as $rows) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $line = $this->text($row['line'] ?? null, 120);
                $name = $this->text($row['name'] ?? null, 200);
                if ($line === null && $name === null) {
                    continue;
                }
                $byLine[$line ?? $name] = array_filter([
                    'line' => $line,
                    'item' => $this->text($row['item'] ?? null, 120),
                    'name' => $name,
                    'qty' => is_numeric($row['qty'] ?? null) ? (int) $row['qty'] : null,
                    'note' => $this->text($row['note'] ?? null, 300),
                ], static fn ($value) => $value !== null && $value !== '');
            }
        }

        return array_slice(array_values($byLine), 0, self::MEALS_MAX);
    }

    /**
     * @param  array<int, mixed>  $log
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function appendAttempt(array $log, array $entry): array
    {
        $log = array_values(array_filter($log, 'is_array'));
        $log[] = $entry;

        return array_slice($log, -self::ATTEMPT_LOG_MAX);
    }

    /**
     * The POS clock in the context, else the log time, else now.
     *
     * @param  array<string, mixed>  $context
     */
    private function time(array $context, SupportDeviceLog $log): Carbon
    {
        $raw = $context['at'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw)->utc();
            } catch (\Throwable) {
            }
        }

        return $log->logged_at !== null ? Carbon::parse($log->logged_at)->utc() : now()->utc();
    }

    private function fault(mixed $raw): string
    {
        $value = strtolower(trim((string) $raw));

        return in_array($value, SupportPrinterIssue::FAULTS, true) ? $value : 'unknown';
    }

    private function port(mixed $raw): ?int
    {
        if (! is_numeric($raw)) {
            return null;
        }
        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    private function text(mixed $raw, int $max): ?string
    {
        if (! is_scalar($raw)) {
            return null;
        }
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
