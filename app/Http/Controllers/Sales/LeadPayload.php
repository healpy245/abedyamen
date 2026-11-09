<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\Door;
use App\Http\Controllers\AgentsWorkspaceController;
use App\Models\Sales\AgentAppointment;
use App\Models\Sales\AgentLead;
use App\Models\Sales\AgentLeadNote;
use App\Models\User;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The JSON both the web page and the phone app read leads and appointments in.
 */
trait LeadPayload
{
    private function agent(Request $request): User
    {
        $user = $request->user();
        // The door lets chatbot accounts through so its pages can send them back to their own side.
        abort_if($user === null || $user->isChatbotOnly(), 403);

        return $user;
    }

    /**
     * Who a lead or appointment can be assigned to.
     *
     * @return list<array{id: int, name: string}>
     */
    private function assignable(): array
    {
        return User::query()->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->canOpen(Door::Agents, 'leads') || $user->canOpen(Door::Agents, 'calendar'))
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return list<array{value: string, label: string}>
     */
    private function states(string $enum): array
    {
        return array_map(fn (BackedEnum $state): array => self::state($state), $enum::cases());
    }

    /**
     * @return array{value: string, label: string}
     */
    private static function state(BackedEnum $state): array
    {
        return ['value' => (string) $state->value, 'label' => $state->label()];
    }

    /**
     * @param  array<string, string>  $cityNames
     * @return array<string, mixed>
     */
    private function leadRow(AgentLead $lead, array $cityNames = []): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'contact' => (string) $lead->contact,
            'phone' => (string) $lead->phone,
            'city_id' => $lead->city_id,
            'city' => $cityNames[(string) $lead->city_id] ?? '',
            'restaurant_id' => $lead->restaurant_id,
            'agent_id' => $lead->agent_id,
            'agent' => (string) $lead->agent?->name,
            'stage' => self::state($lead->stage),
            'next_follow_up_at' => $lead->next_follow_up_at?->toIso8601String(),
            'notes' => $lead->notes->map(fn (AgentLeadNote $note): array => [
                'id' => $note->id,
                'at' => $note->created_at?->toIso8601String(),
                'text' => $note->text,
                'by' => (string) $note->user?->name,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentRow(AgentAppointment $appointment): array
    {
        $lead = $appointment->lead;

        return [
            'id' => $appointment->id,
            'lead_id' => $appointment->agent_lead_id,
            'restaurant_id' => $appointment->restaurant_id,
            'restaurant_name' => (string) $appointment->restaurant_name,
            'title' => $lead !== null ? $lead->name : (string) ($appointment->restaurant_name ?: $appointment->restaurant_id),
            'contact' => (string) $lead?->contact,
            'phone' => (string) $lead?->phone,
            'agent_id' => $appointment->agent_id,
            'agent' => (string) $appointment->agent?->name,
            'at' => $appointment->at->toIso8601String(),
            'type' => self::state($appointment->type),
            'status' => self::state($appointment->status),
            'notes' => (string) $appointment->notes,
        ];
    }

    /**
     * Browsers and the app send instants with an offset; the columns hold them in the app's timezone.
     */
    private function instant(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Area names from kaman.rest in the current language, for the lists and the pickers.
     *
     * @return array<string, string>
     */
    private function cityNames(AgentsWorkspaceController $workspace): array
    {
        $locale = app()->getLocale();
        $names = [];
        foreach ($workspace->catalog()['cities'] as $city) {
            $names[$city['id']] = $city[$locale] ?? $city['en'];
        }

        return $names;
    }
}
