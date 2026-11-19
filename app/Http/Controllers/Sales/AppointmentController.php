<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\Sales\AppointmentStatus;
use App\Enums\Sales\AppointmentType;
use App\Http\Controllers\AgentsWorkspaceController;
use App\Http\Controllers\Controller;
use App\Models\Sales\AgentAppointment;
use App\Models\Sales\AgentLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    use LeadPayload;

    public function index(Request $request, AgentsWorkspaceController $workspace): JsonResponse
    {
        $user = $this->agent($request);

        return response()->json([
            'appointments' => AgentAppointment::query()
                ->tap($this->ownedBy($user))
                ->with(['lead:id,name,contact,phone', 'agent:id,name'])
                ->orderBy('at')
                ->get()
                ->map(fn (AgentAppointment $appointment): array => $this->appointmentRow($appointment))
                ->values()
                ->all(),
            // Only what a meeting's picker needs: the calendar door does not open the leads themselves.
            'leads' => AgentLead::query()->tap($this->ownedBy($user))->latest('id')->get(['id', 'name', 'contact', 'phone'])->values()->all(),
            'restaurants' => collect($workspace->catalog()['companies'])
                ->map(fn (array $company): array => ['id' => $company['id'], 'name' => $company['name']])
                ->values()
                ->all(),
            'types' => $this->states(AppointmentType::class),
            'statuses' => $this->states(AppointmentStatus::class),
            'agents' => $this->assignable($user),
            'me' => $user->id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->agent($request);
        $data = $this->keepOwnAgent($user, $this->validated($request, creating: true));
        if (! empty($data['lead_id'])) {
            $this->authorizeOwn($user, AgentLead::query()->whereKey($data['lead_id'])->value('agent_id'));
        }
        if (empty($data['lead_id']) && empty($data['restaurant_id'])) {
            throw ValidationException::withMessages(['lead_id' => __('sales.flash.appointment_needs_target')]);
        }

        $appointment = AgentAppointment::query()->create($this->attributes($data) + [
            'agent_id' => $user->id,
            'created_by' => $user->id,
        ]);
        $this->followUp($appointment);

        return $this->answer($appointment, 201);
    }

    public function update(Request $request, AgentAppointment $appointment): JsonResponse
    {
        $user = $this->agent($request);
        $this->authorizeOwn($user, $appointment->agent_id);
        $data = $this->keepOwnAgent($user, $this->validated($request, creating: false));

        $appointment->update($this->attributes($data));
        if (array_key_exists('at', $data) || array_key_exists('lead_id', $data)) {
            $this->followUp($appointment);
        }

        return $this->answer($appointment);
    }

    public function destroy(Request $request, AgentAppointment $appointment): JsonResponse
    {
        $this->authorizeOwn($this->agent($request), $appointment->agent_id);
        $appointment->delete();

        return response()->json(['message' => __('sales.flash.appointment_deleted')]);
    }

    /**
     * See LeadController::import(). A meeting on a lead that did not come over keeps the lead's name,
     * so it still says who it was with.
     */
    public function import(Request $request): JsonResponse
    {
        $user = $this->agent($request);
        $data = $request->validate([
            'browser' => ['required', 'string', 'max:64'],
            'appointments' => ['present', 'array', 'max:2000'],
        ]);

        $imported = DB::transaction(function () use ($data, $user): int {
            $count = 0;
            foreach ($data['appointments'] as $row) {
                $at = is_array($row) ? $this->instant($row['at'] ?? null) : null;
                if ($at === null || ! isset($row['id'])) {
                    continue;
                }
                $localLead = (string) ($row['lead_id'] ?? '');
                $lead = $localLead === '' ? null : AgentLead::query()
                    ->where('import_key', LeadController::importKey($user->id, $data['browser'], $localLead))
                    ->first();
                $appointment = AgentAppointment::query()->firstOrCreate(
                    ['import_key' => LeadController::importKey($user->id, $data['browser'], 'appointment:'.$row['id'])],
                    [
                        'agent_lead_id' => $lead?->id,
                        'restaurant_id' => mb_substr((string) ($row['restaurant_id'] ?? ''), 0, 64) ?: null,
                        'restaurant_name' => mb_substr((string) ($row['restaurant_name'] ?? ''), 0, 255) ?: null,
                        'agent_id' => $user->id,
                        'at' => $at,
                        'type' => AppointmentType::tryFrom((string) ($row['type'] ?? ''))?->value ?? AppointmentType::Visit->value,
                        'status' => AppointmentStatus::tryFrom((string) ($row['status'] ?? ''))?->value ?? AppointmentStatus::Scheduled->value,
                        'notes' => (string) ($row['notes'] ?? ''),
                        'created_by' => $user->id,
                    ],
                );
                if ($appointment->wasRecentlyCreated) {
                    $count++;
                }
            }

            return $count;
        });

        return response()->json(['imported' => $imported]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'lead_id' => ['sometimes', 'nullable', 'integer', Rule::exists('agent_leads', 'id')],
            'restaurant_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'restaurant_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'agent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'at' => [$creating ? 'required' : 'sometimes', 'date'],
            'type' => ['sometimes', Rule::enum(AppointmentType::class)],
            'status' => ['sometimes', Rule::enum(AppointmentStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        if (array_key_exists('lead_id', $data)) {
            $data['agent_lead_id'] = $data['lead_id'];
            unset($data['lead_id']);
        }
        if (array_key_exists('at', $data)) {
            $data['at'] = $this->instant($data['at']);
        }

        return $data;
    }

    /**
     * A meeting booked on a lead is that lead's next follow-up.
     */
    private function followUp(AgentAppointment $appointment): void
    {
        if ($appointment->restaurant_id === null && $appointment->agent_lead_id !== null) {
            AgentLead::query()->whereKey($appointment->agent_lead_id)->update(['next_follow_up_at' => $appointment->at]);
        }
    }

    private function answer(AgentAppointment $appointment, int $status = 200): JsonResponse
    {
        $appointment->load(['lead:id,name,contact,phone', 'agent:id,name']);

        return response()->json([
            'message' => __('sales.flash.appointment_saved'),
            'appointment' => $this->appointmentRow($appointment),
        ], $status);
    }
}
