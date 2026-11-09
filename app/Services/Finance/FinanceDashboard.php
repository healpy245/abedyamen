<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Finance\HardwareStatus;
use App\Enums\Finance\PaymentRecordStatus;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\ServiceStatus;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinancePayment;
use App\Models\Finance\StorageItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceDashboard
{
    private const MONTHS = 6;

    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $start = $now->copy()->startOfMonth()->subMonths(self::MONTHS - 1);

        $all = FinanceClient::query()->get([
            'id', 'billing_client_id', 'service_status', 'payment_status', 'hardware_status', 'has_payment_method', 'monthly_fee',
        ]);
        // Counts are per finance account; restaurants sharing one are counted once, under it.
        $clients = $all->whereNull('billing_client_id');
        $activeAccounts = $clients->where('service_status', ServiceStatus::Active)->pluck('id')->flip();
        $income = $this->incomeByMonth($start, $now);
        $thisMonth = $income[$now->format('Y-m')] ?? 0.0;
        $lastMonth = $income[$now->copy()->subMonthNoOverflow()->format('Y-m')] ?? 0.0;

        $trend = [];
        $cursor = $start->copy();
        for ($i = 0; $i < self::MONTHS; $i++) {
            $key = $cursor->format('Y-m');
            $trend[] = [
                'label' => $cursor->translatedFormat('M'),
                'value' => round($income[$key] ?? 0.0, 2),
                'current' => $key === $now->format('Y-m'),
            ];
            $cursor->addMonth();
        }

        $paymentMix = collect(PaymentStatus::cases())->map(fn (PaymentStatus $status): array => [
            'status' => $status,
            'count' => $clients->where('payment_status', $status)->count(),
        ])->all();

        return [
            // Every restaurant's fee counts, as long as its account is active.
            'mrr' => round((float) $all->filter(fn (FinanceClient $client): bool => isset($activeAccounts[$client->billing_client_id ?? $client->id]))->sum('monthly_fee'), 2),
            'collected' => round($thisMonth, 2),
            'delta' => $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100, 1) : null,
            'active' => $clients->where('service_status', ServiceStatus::Active)->count(),
            'total' => $clients->count(),
            'failed' => $clients->where('payment_status', PaymentStatus::Failed)->count(),
            'no_method' => $clients->where('has_payment_method', false)->where('service_status', ServiceStatus::Active)->count(),
            'hardware_due' => $clients->whereIn('hardware_status', [HardwareStatus::Unpaid, HardwareStatus::Partial])->count(),
            'trend' => $trend,
            'trend_max' => max(1.0, (float) collect($trend)->max('value')),
            'payment_mix' => $paymentMix,
            'low_stock' => StorageItem::query()
                ->where('active', true)
                ->where('track_stock', true)
                ->whereColumn('stock', '<=', 'low_stock')
                ->orderBy('stock')
                ->limit(5)
                ->get(),
            'recent' => FinanceInvoice::query()->with('client:id,name,logo')->latest('issued_on')->latest('id')->limit(6)->get(),
        ];
    }

    /**
     * Money received per month: paid subscription payments plus receipt-type documents, minus credits.
     *
     * @return array<string, float>
     */
    private function incomeByMonth(Carbon $start, Carbon $now): array
    {
        $end = $now->copy()->endOfMonth();
        $totals = [];

        FinancePayment::query()
            ->where('status', PaymentRecordStatus::Paid)
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('paid_on', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(fn ($inner) => $inner->whereNull('paid_on')->whereBetween('period', [$start->toDateString(), $end->toDateString()]));
            })
            ->get(['amount', 'paid_on', 'period'])
            ->each(function (FinancePayment $payment) use (&$totals): void {
                $key = ($payment->paid_on ?? $payment->period)->format('Y-m');
                $totals[$key] = ($totals[$key] ?? 0.0) + (float) $payment->amount;
            });

        $this->documents($start, $end)->each(function (FinanceInvoice $invoice) use (&$totals): void {
            $sign = $invoice->type->incomeSign();
            if ($sign === 0) {
                return;
            }
            $key = $invoice->issued_on->format('Y-m');
            $totals[$key] = ($totals[$key] ?? 0.0) + $sign * (float) $invoice->total;
        });

        return $totals;
    }

    /**
     * @return Collection<int, FinanceInvoice>
     */
    private function documents(Carbon $start, Carbon $end): Collection
    {
        return FinanceInvoice::query()
            ->whereBetween('issued_on', [$start->toDateString(), $end->toDateString()])
            ->get(['type', 'total', 'issued_on']);
    }
}
