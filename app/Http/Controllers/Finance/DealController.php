<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\Sales\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Sales\SalesQuote;
use App\Services\Sales\DealRequests;
use App\Services\Sales\SaleSync;
use App\Support\FinanceMoney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Close-deal requests from sales agents, waiting for a Finance user to confirm the client.
 */
class DealController extends Controller
{
    public function index(Request $request): View
    {
        $relations = ['client', 'agent:id,name', 'items.catalogItem', 'items.storageItem', 'files', 'invoice', 'confirmer:id,name', 'signature'];

        $pending = SalesQuote::query()
            ->with($relations)
            ->where('status', QuoteStatus::Pending)
            ->orderBy('requested_at')
            ->get();

        $recent = SalesQuote::query()
            ->with($relations)
            ->whereNotNull('requested_at')
            ->whereIn('status', [QuoteStatus::Sold, QuoteStatus::Returned])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        // ?open= (from a notification) opens that deal's modal on load; it must be one of the listed deals.
        $open = $pending->concat($recent)->firstWhere('id', $request->integer('open'));

        return view('workspace.finance.deals', [
            'page' => 'deals',
            'pending' => $pending,
            'recent' => $recent,
            'open' => $open,
        ]);
    }

    public function confirm(Request $request, SalesQuote $quote, DealRequests $deals): RedirectResponse
    {
        if ($quote->status !== QuoteStatus::Pending) {
            return back()->with('error', __('finance.deals.not_pending'));
        }

        $devicesTotal = round((float) $quote->one_time_total * (1 + (float) config('finance.vat_rate', 18) / 100), 2);
        $data = $request->validate([
            'next_billing_date' => [(float) $quote->monthly_total > 0 ? 'required' : 'nullable', 'date'],
            'device_payment' => ['nullable', Rule::in(['full', 'partial'])],
            'devices_left' => ['nullable', 'required_if:device_payment,partial', 'numeric', 'min:0', 'max:'.$devicesTotal],
        ]);

        // Partly paid: Finance types what is still left, so the invoice is made for what was actually paid.
        $devicesPaid = ($data['device_payment'] ?? 'full') === 'partial'
            ? round($devicesTotal - (float) $data['devices_left'], 2)
            : null;

        try {
            $quote = $deals->confirm(
                $quote,
                $request->user(),
                isset($data['next_billing_date']) ? Carbon::parse($data['next_billing_date']) : null,
                $devicesPaid,
            );
        } catch (ValidationException $e) {
            // Back to this deal's modal, which shows why (the toast alone sits under the reopened modal).
            return redirect()
                ->route('workspace.finance.deals', ['open' => $quote->id])
                ->withErrors($e->errors());
        }

        return redirect()
            ->route('workspace.finance.deals')
            ->with('success', __('finance.deals.confirmed', ['client' => $quote->client?->name]));
    }

    /**
     * Continue payment: the client paid more towards devices still owed. Creates the invoice for that amount.
     */
    public function payDevices(Request $request, SalesQuote $quote, SaleSync $sync): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.(float) $quote->devices_due],
        ]);

        $invoice = $sync->payDevices($quote, (float) $data['amount'], $request->user());

        return redirect()
            ->route('workspace.finance.clients', ['open' => $quote->finance_client_id, 'tab' => 'invoices'])
            ->with('success', __('finance.devices.paid', ['number' => $invoice->displayNumber(), 'left' => FinanceMoney::format($quote->fresh()->devices_due)]));
    }

    public function return(Request $request, SalesQuote $quote, DealRequests $deals): RedirectResponse
    {
        if ($quote->status !== QuoteStatus::Pending) {
            return back()->with('error', __('finance.deals.not_pending'));
        }

        $data = $request->validate(['finance_note' => ['required', 'string', 'max:2000']]);
        $deals->returnToAgent($quote, trim($data['finance_note']));

        return redirect()
            ->route('workspace.finance.deals')
            ->with('success', __('finance.deals.returned', ['client' => $quote->client?->name]));
    }

    /**
     * Opening a notification marks it read and goes to the deal it is about.
     */
    public function notification(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless(
            (int) $notification->notifiable_id === (int) $request->user()->id
            && $notification->notifiable_type === $request->user()::class,
            404,
        );

        $notification->markAsRead();
        $url = $notification->data['url'] ?? null;

        return redirect()->to(is_string($url) && $url !== '' ? $url : route('workspace.finance.deals'));
    }
}
