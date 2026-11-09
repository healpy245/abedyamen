<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentRecordStatus;
use App\Enums\Finance\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancePaymentRequest;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinancePayment;
use App\Models\Finance\FinancePaymentFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monthly payments: record, settle a failed month, attachments, and the printable invoice.
 */
class PaymentController extends Controller
{
    public function store(StoreFinancePaymentRequest $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validated();
        $status = PaymentRecordStatus::from($data['status']);

        DB::transaction(function () use ($request, $client, $data, $status): void {
            $payment = $client->payments()->create([
                'period' => Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth()->toDateString(),
                'amount' => $data['amount'],
                'status' => $status,
                'method' => $data['method'] ?? null,
                'paid_on' => $status === PaymentRecordStatus::Paid ? ($data['paid_on'] ?? now()->toDateString()) : null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                // What was billed that month, so the invoice still lists it after the plan changes.
                'items' => $client->billingLines(),
            ]);
            $this->attach($payment, $request->file('files', []));
        });

        // A newly recorded failure is what the team acts on, so flag the client right away.
        if ($status === PaymentRecordStatus::Failed) {
            $client->forceFill(['payment_status' => PaymentStatus::Failed])->save();
        }

        return $this->back($client)->with('success', __('finance.flash.payment_saved'));
    }

    /**
     * Collects a failed or pending month: it becomes paid with the method, proof and note given.
     */
    public function settle(StoreFinancePaymentRequest $request, FinancePayment $payment): RedirectResponse
    {
        $client = $payment->client;
        abort_unless($payment->isOpen(), 422);
        $data = $request->validated();

        DB::transaction(function () use ($request, $payment, $data): void {
            $payment->fill([
                'status' => PaymentRecordStatus::Paid,
                'method' => PaymentMethod::from($data['method']),
                'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? $payment->reference,
                'note' => filled($data['note'] ?? null) ? $data['note'] : $payment->note,
            ])->save();
            $this->attach($payment, $request->file('files', []));
        });

        // Nothing left failing: the client is back in good standing.
        if ($client->payment_status === PaymentStatus::Failed
            && ! $client->payments()->where('status', PaymentRecordStatus::Failed)->exists()) {
            $client->forceFill(['payment_status' => PaymentStatus::Active])->save();
        }

        return $this->back($client)->with('success', __('finance.flash.payment_settled'));
    }

    public function destroy(FinancePayment $payment): RedirectResponse
    {
        $client = $payment->client;
        $paths = $payment->files()->pluck('path')->all();
        $payment->delete();
        Storage::disk(FinancePaymentFile::DISK)->delete($paths);

        return $this->back($client)->with('success', __('finance.flash.payment_deleted'));
    }

    public function invoice(FinancePayment $payment): View
    {
        $payment->load('client');

        return view('workspace.finance.payment-invoice', ['payment' => $payment]);
    }

    public function file(FinancePaymentFile $file): StreamedResponse
    {
        $disk = Storage::disk(FinancePaymentFile::DISK);
        abort_unless($disk->exists($file->path), 404);

        return $disk->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    /**
     * @param  array<int, UploadedFile>|UploadedFile|null  $files
     */
    private function attach(FinancePayment $payment, array|UploadedFile|null $files): void
    {
        foreach (array_filter(is_array($files) ? $files : [$files]) as $upload) {
            $payment->files()->create([
                'path' => $upload->store('finance/payments/'.$payment->id, FinancePaymentFile::DISK),
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'mime' => (string) ($upload->getMimeType() ?: 'application/octet-stream'),
                'size' => (int) $upload->getSize(),
            ]);
        }
    }

    private function back(FinanceClient $client): RedirectResponse
    {
        return redirect()->route('workspace.finance.clients', array_merge(ClientController::keptFilters(), ['open' => $client->id, 'tab' => 'payments']));
    }
}
