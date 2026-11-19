<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\Sales\LeadStage;
use App\Http\Controllers\AgentsWorkspaceController;
use App\Http\Controllers\Controller;
use App\Models\Sales\AgentLead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The leads report: the leads the leads page shows with the same filters, as a Kaman PDF.
 * A worker only gets their own leads; an agents admin gets everyone's.
 */
class LeadReportController extends Controller
{
    use LeadPayload;

    public const PERIODS = ['week', 'month', 'year', 'custom'];

    public function show(Request $request, AgentsWorkspaceController $workspace): View
    {
        $user = $this->agent($request);
        [$from, $to] = self::range((string) $request->query('period', ''), $request->query('from'), $request->query('to'));
        $agentId = $user->managesAgents() ? $request->integer('agent') : 0;
        $city = trim((string) $request->query('city', ''));
        $search = trim((string) $request->query('q', ''));
        $cities = $this->cityNames($workspace);

        $leads = AgentLead::query()
            ->tap($this->ownedBy($user))
            ->with('agent:id,name')
            ->when($agentId > 0, fn ($query) => $query->where('agent_id', $agentId))
            ->when($city !== '', fn ($query) => $query->where('city_id', $city))
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('contact', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            }))
            ->latest('created_at')
            ->get();

        return view('workspace.agents.leads-report', [
            'leads' => $leads,
            'cities' => $cities,
            'stages' => $this->stageCounts($leads),
            'agents' => $user->managesAgents() ? $this->agentCounts($leads) : collect(),
            'from' => $from,
            'to' => $to,
            'filters' => array_filter([
                __('sales.report.agent') => $agentId > 0 ? User::query()->whereKey($agentId)->value('name') : null,
                __('sales.report.city') => $city !== '' ? ($cities[$city] ?? $city) : null,
                __('sales.report.search') => $search !== '' ? $search : null,
            ]),
            'user' => $user,
            'pdf' => $request->boolean('pdf'),
        ]);
    }

    /**
     * The period the quick picks stand for, up to now; a custom range runs from the start of one day to the end of another.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function range(string $period, mixed $from = null, mixed $to = null): array
    {
        $now = now();

        return match ($period) {
            'week' => [$now->copy()->subWeek()->startOfDay(), $now->copy()],
            'month' => [$now->copy()->subMonthNoOverflow()->startOfDay(), $now->copy()],
            'year' => [$now->copy()->subYear()->startOfDay(), $now->copy()],
            'custom' => [self::day($from)?->startOfDay(), self::day($to)?->endOfDay()],
            default => [null, null],
        };
    }

    private static function day(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
    }

    /**
     * Every stage in board order, grouped as the board groups them, with how many leads are in each.
     *
     * @param  Collection<int, AgentLead>  $leads
     * @return list<array{label: string, count: int, subs: list<array{label: string, count: int}>}>
     */
    private function stageCounts(Collection $leads): array
    {
        $groups = [];
        foreach (LeadStage::cases() as $stage) {
            $key = $stage->group();
            $groups[$key] ??= ['label' => $stage->groupLabel(), 'count' => 0, 'subs' => []];
            $count = $leads->filter(fn (AgentLead $lead): bool => $lead->stage === $stage)->count();
            $groups[$key]['count'] += $count;
            if ($key !== $stage->value) {
                $groups[$key]['subs'][] = ['label' => $stage->label(), 'count' => $count];
            }
        }

        return array_values($groups);
    }

    /**
     * @param  Collection<int, AgentLead>  $leads
     * @return Collection<int, array{name: string, count: int}>
     */
    private function agentCounts(Collection $leads): Collection
    {
        return $leads->groupBy(fn (AgentLead $lead): string => (string) ($lead->agent?->name ?? '—'))
            ->map(fn (Collection $rows, string $name): array => ['name' => $name, 'count' => $rows->count()])
            ->sortByDesc('count')
            ->values();
    }
}
