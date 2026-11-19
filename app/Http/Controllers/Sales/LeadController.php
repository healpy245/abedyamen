<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\Sales\LeadStage;
use App\Http\Controllers\AgentsWorkspaceController;
use App\Http\Controllers\Controller;
use App\Models\Sales\AgentLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    use LeadPayload;

    public function index(Request $request, AgentsWorkspaceController $workspace): JsonResponse
    {
        $user = $this->agent($request);
        $cities = $this->cityNames($workspace);

        return response()->json([
            'leads' => AgentLead::query()
                ->tap($this->ownedBy($user))
                ->with(['agent:id,name', 'notes.user:id,name'])
                ->latest('id')
                ->get()
                ->map(fn (AgentLead $lead): array => $this->leadRow($lead, $cities))
                ->values()
                ->all(),
            'stages' => $this->states(LeadStage::class),
            'agents' => $this->assignable($user),
            'cities' => collect($cities)->map(fn (string $name, int|string $id): array => ['id' => (string) $id, 'name' => $name])->values()->all(),
            'me' => $user->id,
        ]);
    }

    public function store(Request $request, AgentsWorkspaceController $workspace): JsonResponse
    {
        $user = $this->agent($request);
        $data = $this->keepOwnAgent($user, $this->validated($request, creating: true));

        $lead = AgentLead::query()->create($this->attributes($data) + [
            'agent_id' => $user->id,
            'stage' => LeadStage::New->value,
            'created_by' => $user->id,
        ]);

        return $this->answer($lead, $workspace, 201);
    }

    public function update(Request $request, AgentLead $lead, AgentsWorkspaceController $workspace): JsonResponse
    {
        $user = $this->agent($request);
        $this->authorizeOwn($user, $lead->agent_id);
        $lead->update($this->attributes($this->keepOwnAgent($user, $this->validated($request, creating: false))));

        return $this->answer($lead, $workspace);
    }

    public function note(Request $request, AgentLead $lead, AgentsWorkspaceController $workspace): JsonResponse
    {
        $user = $this->agent($request);
        $this->authorizeOwn($user, $lead->agent_id);
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        $lead->notes()->create(['user_id' => $user->id, 'text' => $data['text']]);

        return $this->answer($lead, $workspace);
    }

    public function destroy(Request $request, AgentLead $lead): JsonResponse
    {
        $this->authorizeOwn($this->agent($request), $lead->agent_id);
        // Its quotes have no client to fall back on yet, and may be another agent's.
        if ($lead->quotes()->exists()) {
            return response()->json(['message' => __('sales.errors.lead_has_quotes')], 422);
        }
        $lead->delete();

        return response()->json(['message' => __('sales.flash.lead_deleted')]);
    }

    /**
     * What a browser kept in local storage before leads moved here. A row comes in once however many
     * times the browser retries, and it was its own user's, so that user becomes its agent.
     */
    public function import(Request $request): JsonResponse
    {
        $user = $this->agent($request);
        $data = $request->validate([
            'browser' => ['required', 'string', 'max:64'],
            'leads' => ['present', 'array', 'max:2000'],
        ]);

        $imported = DB::transaction(function () use ($data, $user): int {
            $count = 0;
            foreach ($data['leads'] as $row) {
                $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
                if ($name === '' || ! isset($row['id'])) {
                    continue;
                }
                $lead = AgentLead::query()->firstOrCreate(
                    ['import_key' => self::importKey($user->id, $data['browser'], (string) $row['id'])],
                    [
                        'name' => mb_substr($name, 0, 255),
                        'contact' => mb_substr((string) ($row['contact'] ?? ''), 0, 255),
                        'phone' => mb_substr((string) ($row['phone'] ?? ''), 0, 40),
                        'city_id' => mb_substr((string) ($row['city_id'] ?? ''), 0, 64) ?: null,
                        'restaurant_id' => mb_substr((string) ($row['restaurant_id'] ?? ''), 0, 64) ?: null,
                        'agent_id' => $user->id,
                        'stage' => LeadStage::fromInput($row['stage'] ?? '')?->value ?? LeadStage::New->value,
                        'next_follow_up_at' => $this->instant($row['next_follow_up_at'] ?? null),
                        'created_by' => $user->id,
                    ],
                );
                if (! $lead->wasRecentlyCreated) {
                    continue;
                }
                $count++;
                foreach (is_array($row['notes'] ?? null) ? $row['notes'] : [] as $note) {
                    $text = is_array($note) ? trim((string) ($note['text'] ?? '')) : '';
                    if ($text === '') {
                        continue;
                    }
                    $lead->notes()->create([
                        'user_id' => $user->id,
                        'text' => $text,
                        'created_at' => $this->instant($note['at'] ?? null) ?? now(),
                    ]);
                }
            }

            return $count;
        });

        return response()->json(['imported' => $imported]);
    }

    public static function importKey(int $userId, string $browser, string $localId): string
    {
        return hash('sha256', $userId.'|'.$browser.'|'.$localId);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        // The phone app may still send a stage from before "After meeting" replaced them.
        if ($request->has('stage') && array_key_exists((string) $request->input('stage'), LeadStage::LEGACY)) {
            $request->merge(['stage' => LeadStage::LEGACY[(string) $request->input('stage')]]);
        }

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'string', 'max:40'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'restaurant_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'agent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'stage' => ['sometimes', Rule::enum(LeadStage::class)],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        if (array_key_exists('next_follow_up_at', $data)) {
            $data['next_follow_up_at'] = $this->instant($data['next_follow_up_at']);
        }

        return $data;
    }

    private function answer(AgentLead $lead, AgentsWorkspaceController $workspace, int $status = 200): JsonResponse
    {
        $lead->load(['agent:id,name', 'notes.user:id,name']);

        return response()->json([
            'message' => __('sales.flash.lead_saved'),
            'lead' => $this->leadRow($lead, $this->cityNames($workspace)),
        ], $status);
    }
}
