<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesQuoteSignature;
use App\Services\Sales\QuoteContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The page the client opens from WhatsApp: no login, the long random token in the link is the key.
 * A cancelled link says so instead of showing the contract.
 */
class ContractController extends Controller
{
    public function show(string $token, QuoteContract $contract): View
    {
        $signature = $this->find($token);
        app()->setLocale(QuoteContract::LANG);

        if ($signature->status === SalesQuoteSignature::CANCELLED) {
            return view('workspace.agents.contract-closed');
        }

        $quote = $signature->quote;

        return view('workspace.agents.contract', [
            'quote' => $quote,
            'contract' => $contract->data($quote, $signature),
            'signature' => $signature->isSigned() ? $signature : null,
            'link' => $signature,
            'mode' => 'sign',
            'pdf' => false,
            'filename' => (string) $quote->number,
        ]);
    }

    public function sign(Request $request, string $token, QuoteContract $contract): RedirectResponse
    {
        $signature = $this->find($token);
        app()->setLocale(QuoteContract::LANG);
        if ($signature->status === SalesQuoteSignature::CANCELLED) {
            return redirect()->route('contracts.show', $token);
        }

        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:191'],
            'signer_business_number' => ['nullable', 'string', 'max:40'],
            'signer_address' => ['nullable', 'string', 'max:191'],
            'agree' => ['accepted'],
            'signature' => ['required', 'string', 'max:900000'],
        ], [
            'signer_name.required' => __('sales.contract.errors.no_name'),
            'agree.accepted' => __('sales.contract.errors.no_agree'),
            'signature.required' => __('sales.contract.errors.no_signature'),
        ]);

        $contract->sign($signature, $data, $request);

        return redirect()->route('contracts.show', $token)->with('signed', true);
    }

    private function find(string $token): SalesQuoteSignature
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{32,64}$/', $token) === 1, 404);

        return SalesQuoteSignature::query()->with('quote')->where('token', $token)->firstOrFail();
    }
}
