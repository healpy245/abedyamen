<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\Door;
use App\Enums\Finance\StorageCategory;
use App\Enums\Sales\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SaveQuoteRequest;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\StorageItem;
use App\Models\Sales\AgentLead;
use App\Models\Sales\SalesCatalogItem;
use App\Models\Sales\SalesQuote;
use App\Models\Sales\SalesQuoteFile;
use App\Models\User;
use App\Services\Finance\FinanceClientDirectory;
use App\Services\Sales\DealRequests;
use App\Services\Sales\LeadConversion;
use App\Services\Sales\QuoteBuilder;
use App\Services\Sales\QuoteWhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuoteController extends Controller
{
    public function index(Request $request, FinanceClientDirectory $directory): View
    {
        $user = $this->agent($request);
        $directory->syncIfStale();

        $status = QuoteStatus::tryFrom((string) $request->query('status', ''));
        $agentId = $user->is_admin ? $request->integer('agent') : 0;
        $search = trim((string) $request->query('q', ''));

        $visible = SalesQuote::query()->when(! $user->is_admin, fn ($query) => $query->where('agent_id', $user->id));

        $quotes = (clone $visible)
            ->with(['client:id,name', 'lead:id,name', 'agent:id,name'])
            ->withCount('items')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($agentId > 0, fn ($query) => $query->where('agent_id', $agentId))
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                $inner->where('number', 'like', '%'.$search.'%')
                    ->orWhere('contact_name', 'like', '%'.$search.'%')
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('lead', fn ($lead) => $lead->where('name', 'like', '%'.$search.'%'));
            }))
            ->orderByDesc('number')
            ->get();

        $sold = (clone $visible)->where('status', QuoteStatus::Sold);
        $open = SalesQuote::query()
            ->with(['client', 'lead.client', 'agent:id,name', 'items.catalogItem', 'items.storageItem', 'invoice', 'files'])
            ->when(! $user->is_admin, fn ($query) => $query->where('agent_id', $user->id))
            ->find($request->integer('open'));

        return view('workspace.agents.sales', [
            'page' => 'sales',
            'quotes' => $quotes,
            'open' => $open,
            'search' => $search,
            'filters' => ['status' => $status?->value ?? '', 'agent' => $agentId ?: ''],
            'stats' => [
                'open' => (clone $visible)->whereIn('status', [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Returned])->count(),
                'pending' => (clone $visible)->where('status', QuoteStatus::Pending)->count(),
                'sold' => (clone $sold)->count(),
                'monthly' => (float) (clone $sold)->sum('monthly_total'),
                'one_time' => (float) (clone $sold)->sum('one_time_total'),
            ],
            'agents' => $user->is_admin
                ? User::query()->whereIn('id', SalesQuote::query()->select('agent_id')->whereNotNull('agent_id'))->orderBy('name')->pluck('name', 'id')->all()
                : [],
            'clients' => FinanceClient::query()->orderBy('name')->get(['id', 'name', 'contact_name', 'phone']),
            'leads' => $user->canOpen(Door::Agents, 'leads') ? AgentLead::query()->orderBy('name')->get(['id', 'name', 'contact', 'phone']) : null,
            'catalog' => $this->catalog(),
        ]);
    }

    public function store(SaveQuoteRequest $request, QuoteBuilder $builder): RedirectResponse
    {
        $quote = $builder->save(null, $request->validated(), $request->user());

        return redirect()
            ->route('workspace.agents.sales', ['open' => $quote->id])
            ->with('success', __('sales.flash.created', ['quote' => $quote->title()]));
    }

    public function update(SaveQuoteRequest $request, SalesQuote $quote, QuoteBuilder $builder): RedirectResponse
    {
        $this->authorizeQuote($request, $quote);
        if ($quote->isLocked()) {
            return back()->with('error', __('sales.errors.locked'));
        }

        $builder->save($quote, $request->validated(), $quote->agent ?? $request->user());

        return redirect()
            ->route('workspace.agents.sales', ['open' => $quote->id])
            ->with('success', __('sales.flash.saved', ['quote' => $quote->title()]));
    }

    /**
     * Asks MFITGROUP Finance to close the deal. The agent explains how the client pays and attaches evidence.
     */
    public function requestDeal(Request $request, SalesQuote $quote, DealRequests $deals, LeadConversion $conversion): RedirectResponse
    {
        $this->authorizeQuote($request, $quote);
        if ($quote->isLocked()) {
            return back()->with('error', __('sales.errors.locked'));
        }
        if (! $conversion->settle($quote)) {
            return back()->with('error', __('sales.errors.lead_not_converted'));
        }

        $data = $request->validate([
            'deal_note' => ['required', 'string', 'max:3000'],
            'files' => [$quote->files()->exists() ? 'nullable' : 'required', 'array', 'max:8'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192'],
        ], [
            'deal_note.required' => __('sales.errors.no_deal_note'),
            'files.required' => __('sales.errors.no_evidence'),
        ]);

        $deals->request($quote, trim($data['deal_note']), $request->file('files', []), $request->user());

        return redirect()
            ->route('workspace.agents.sales', ['open' => $quote->id])
            ->with('success', __('sales.flash.requested', ['quote' => $quote->title()]));
    }

    /**
     * Links the quote's lead to the client it became, after which the deal can go to Finance.
     */
    public function convertLead(Request $request, SalesQuote $quote, LeadConversion $conversion): RedirectResponse
    {
        $this->authorizeQuote($request, $quote);
        $data = $request->validate([
            'finance_client_id' => ['required', 'integer', Rule::exists('finance_clients', 'id')],
        ], [
            'finance_client_id.required' => __('sales.errors.no_converted_client'),
        ]);

        $lead = $quote->lead;
        if ($lead === null) {
            return back()->with('error', __('sales.errors.no_lead'));
        }

        // A lead already linked stays with its client.
        $client = $lead->client ?? FinanceClient::query()->findOrFail($data['finance_client_id']);
        $conversion->convert($lead, $client);

        return redirect()
            ->route('workspace.agents.sales', ['open' => $quote->id])
            ->with('success', __('sales.flash.converted', ['lead' => $lead->name, 'client' => $client->name]));
    }

    /**
     * Sends the quote PDF (made in the browser) to the client on WhatsApp.
     */
    public function whatsapp(Request $request, SalesQuote $quote, QuoteWhatsApp $whatsapp): JsonResponse
    {
        $this->authorizeQuote($request, $quote);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:1000'],
            'lang' => ['required', Rule::in(SalesQuote::DOCUMENT_LANGUAGES)],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            $whatsapp->send(
                $quote->loadMissing('client'),
                $data['phone'],
                (string) ($data['message'] ?? ''),
                $request->file('pdf'),
                __('sales.doc.file_name', ['number' => $quote->number], $data['lang']).'.pdf',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => __('sales.flash.whatsapp_sent', ['phone' => $data['phone']])]);
    }

    /**
     * Payment evidence: the agent who sent it and Finance users can open it.
     */
    public function file(Request $request, SalesQuoteFile $file): StreamedResponse
    {
        if (! $request->user()?->canOpenSubDoor(\App\Enums\Door::Finance, 'deals')) {
            $this->authorizeQuote($request, $file->quote);
        }
        $disk = Storage::disk(SalesQuoteFile::DISK);
        abort_unless($disk->exists($file->path), 404);

        return $disk->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function destroy(Request $request, SalesQuote $quote): RedirectResponse
    {
        $this->authorizeQuote($request, $quote);
        if ($quote->isLocked()) {
            return back()->with('error', __('sales.errors.locked'));
        }

        $title = $quote->title();
        $quote->delete();

        return redirect()
            ->route('workspace.agents.sales')
            ->with('success', __('sales.flash.deleted', ['quote' => $title]));
    }

    /**
     * The quote panel, loaded into the page's dialog without a reload.
     */
    public function panel(Request $request, SalesQuote $quote): View
    {
        $this->authorizeQuote($request, $quote);
        $quote->load(['client', 'lead.client', 'agent:id,name', 'items.catalogItem', 'items.storageItem', 'invoice', 'files']);

        return view('workspace.agents.partials.quote-panel', ['quote' => $quote]);
    }

    /**
     * The printable quote. With ?pdf=1 the page turns itself into a PDF download (the panel loads it in a hidden frame).
     */
    public function document(Request $request, SalesQuote $quote): View
    {
        $this->authorizeQuote($request, $quote);

        $lang = (string) $request->query('lang', $quote->lang);
        $lang = in_array($lang, SalesQuote::DOCUMENT_LANGUAGES, true) ? $lang : $quote->lang;
        app()->setLocale($lang);
        $quote->load(['client', 'lead', 'agent:id,name', 'items.catalogItem', 'items.storageItem']);

        return view('workspace.agents.quote-document', [
            'quote' => $quote,
            'lang' => $lang,
            'pdf' => in_array((string) $request->query('pdf'), ['1', 'blob'], true),
            'filename' => trim((string) $request->query('name', '')) ?: (string) $quote->number,
        ]);
    }

    private function agent(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null || $user->isChatbotOnly(), 403);

        return $user;
    }

    private function authorizeQuote(Request $request, SalesQuote $quote): void
    {
        $user = $this->agent($request);
        abort_unless($user->is_admin || $quote->agent_id === $user->id, 403);
    }

    /**
     * Everything the quote builder can add, in the shape the page script reads.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function catalog(): array
    {
        $offer = fn (SalesCatalogItem $item): array => [
            'id' => $item->id,
            'name' => $item->localName(),
            'price' => (float) $item->price,
            'billing' => $item->billing,
        ];

        return [
            'base' => SalesCatalogItem::query()->listed('base')->get()->map($offer)->all(),
            'feature' => SalesCatalogItem::query()->listed('feature')->get()->map($offer)->all(),
            // Devices first, then setup and other one-time services. Subscriptions are billed monthly, not sold here.
            'equipment' => StorageItem::query()
                ->where('active', true)
                ->where('category', '!=', StorageCategory::Subscription)
                ->orderByRaw("case when category = 'hardware' then 0 else 1 end")
                ->orderBy('name')
                ->get()
                ->map(fn (StorageItem $item): array => [
                    'id' => $item->id,
                    'name' => $item->localName(),
                    'price' => (float) $item->unit_price,
                    'billing' => 'one_time',
                    'stock' => $item->track_stock ? $item->stock : null,
                ])->all(),
        ];
    }
}
