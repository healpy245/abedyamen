<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\InvoiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceInvoiceRequest;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinanceInvoice;
use App\Services\Finance\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function store(StoreFinanceInvoiceRequest $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validated();

        $invoice = $this->invoices->create(
            $client,
            InvoiceType::from($data['type']),
            Carbon::parse($data['issued_on']),
            $data['lines'],
            $data['notes'] ?? null,
            $request->user(),
        );

        return redirect()->route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'invoices'])
            ->with('success', __('finance.flash.invoice_created', [
                'type' => $invoice->type->label(),
                'number' => $invoice->displayNumber(),
            ]));
    }

    public function show(FinanceInvoice $invoice): View
    {
        $invoice->load(['client', 'items', 'creator:id,name']);

        return view('workspace.finance.invoice', [
            'invoice' => $invoice,
        ]);
    }

    public function destroy(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $client = $invoice->client;
        $label = $invoice->type->label().' '.$invoice->displayNumber();
        $this->invoices->delete($invoice, $request->user());

        return redirect()->route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'invoices'])
            ->with('success', __('finance.flash.invoice_deleted', ['invoice' => $label]));
    }
}
