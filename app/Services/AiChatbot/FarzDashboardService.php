<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\AiChatbot\MansourContact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only roll-up of every بوت الفرز the user can open. Never sends WhatsApp.
 */
class FarzDashboardService
{
    public const GROUPS = [
        MansourInsightService::GROUP_CERTAIN,
        MansourInsightService::GROUP_UNSURE,
        MansourInsightService::GROUP_NOT_VOTING,
        MansourInsightService::GROUP_OTHER_SIDE,
        MansourInsightService::GROUP_REFUSED,
        MansourInsightService::GROUP_NO_REPLY,
    ];

    public const TIMEZONE = 'Asia/Jerusalem';

    private const DAYS = 14;

    public function __construct(
        protected ChatbotAuthorizationService $authz,
        protected MansourInsightService $insights,
        protected AppsInsightService $apps,
        protected DimaInsightService $dimaInsights,
        protected InviteInsightService $inviteInsights,
    ) {}

    /**
     * @return Collection<int, ChatbotInstance>
     */
    public function botsFor(User $user): Collection
    {
        return $this->authz->instancesForUser($user)
            ->filter(fn (ChatbotInstance $bot): bool => $bot->hasMansourIntegration())
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $bots = $this->botsFor($user);
        $key = 'farz-dashboard:'.$user->id.':'.$bots->pluck('id')->implode('-');

        return Cache::remember($key, 8, fn (): array => $this->compute($bots));
    }

    /**
     * @param  Collection<int, ChatbotInstance>  $bots
     * @return array<string, mixed>
     */
    private function compute(Collection $bots): array
    {
        $votingIds = $bots
            ->reject(fn (ChatbotInstance $bot): bool => $bot->isAppsBot() || $bot->isDimaBot() || $bot->isInviteBot())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $dimaIds = $bots
            ->filter(fn (ChatbotInstance $bot): bool => $bot->isDimaBot())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $inviteIds = $bots
            ->filter(fn (ChatbotInstance $bot): bool => $bot->isInviteBot())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $allIds = $bots->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $contactStats = $this->contactStats($votingIds);
        $totals = array_fill_keys(self::GROUPS, 0);
        $groupOf = [];
        $rows = [];
        $appsAnalytics = $this->blankFunnel();

        foreach ($bots as $bot) {
            $apps = $bot->isAppsBot();
            $dima = $bot->isDimaBot();
            $invite = $bot->isInviteBot();
            $summary = match (true) {
                $apps => $this->apps->summarize($bot),
                $dima => $this->dimaInsights->summarize($bot),
                $invite => $this->inviteInsights->summarize($bot),
                default => $this->insights->summarize($bot),
            };
            if (! $apps) {
                foreach ($summary['conversation_ids'] as $group => $conversationIds) {
                    foreach ($conversationIds as $conversationId) {
                        $groupOf[(int) $conversationId] = $group;
                    }
                }
            }

            $groupKeys = match (true) {
                $apps => array_keys(\App\Support\FarzUi::appGroups()),
                $dima => array_keys(\App\Support\FarzUi::dimaGroups()),
                $invite => array_keys(\App\Support\FarzUi::inviteGroups()),
                default => self::GROUPS,
            };
            $groups = [];
            foreach ($groupKeys as $group) {
                $groups[$group] = (int) ($summary[$group] ?? 0);
                if (! $apps && ! $invite && isset($totals[$group])) {
                    $totals[$group] += $groups[$group];
                }
            }

            $stats = $apps || $dima || $invite
                ? ($this->contactStats([(int) $bot->id])[(int) $bot->id] ?? ['contacts' => 0, 'sent' => 0, 'errors' => 0, 'last_sent' => null])
                : ($contactStats[(int) $bot->id] ?? ['contacts' => 0, 'sent' => 0, 'errors' => 0, 'last_sent' => null]);
            $replied = (int) $summary['replied'];
            $quiet = match (true) {
                $apps => AppsPrompt::GROUP_NO_REPLY,
                $dima => DimaPrompt::NO_REPLY,
                $invite => InvitePrompt::NO_REPLY,
                default => MansourInsightService::GROUP_NO_REPLY,
            };
            $reached = $replied + ($groups[$quiet] ?? 0);
            $lead = match (true) {
                $apps => AppsPrompt::GROUP_DOWNLOADED,
                $dima => DimaPrompt::UNITED,
                $invite => InvitePrompt::POSITIVE,
                default => MansourInsightService::GROUP_CERTAIN,
            };
            $funnel = $apps ? $this->apps->funnel($bot) : null;
            if ($funnel !== null) {
                $appsAnalytics['contacted'] += $funnel['contacted'];
                foreach (['aswatkum', 'muwahada'] as $app) {
                    foreach (array_keys($appsAnalytics[$app]) as $key) {
                        $appsAnalytics[$app][$key] += (int) ($funnel[$app][$key] ?? 0);
                    }
                }
            }

            $rows[] = [
                'bot' => $bot,
                'apps' => $apps,
                'dima' => $dima,
                'invite' => $invite,
                'active' => $bot->isBotGloballyActive(),
                'linked' => app(WhapiCloudClient::class)->transportReady($bot->greenapi_url),
                'contacts' => $stats['contacts'],
                'sent' => $stats['sent'],
                'errors' => $stats['errors'],
                'last_sent' => $stats['last_sent'],
                'replied' => $replied,
                'reached' => $reached,
                'reply_rate' => $this->percent($replied, $reached),
                'certain_share' => $this->percent($groups[$lead] ?? 0, $replied),
                'groups' => $groups,
                'funnel' => $funnel,
            ];
        }

        $votingReplied = array_sum($totals) - $totals[MansourInsightService::GROUP_NO_REPLY];
        $votingReached = array_sum($totals);
        $contacts = 0;
        $sent = 0;
        $errors = 0;
        $replied = 0;
        $reached = 0;
        foreach ($rows as $row) {
            $contacts += $row['contacts'];
            $sent += $row['sent'];
            $errors += $row['errors'];
            if ($row['apps'] && is_array($row['funnel'])) {
                $spoke = (int) $row['funnel']['aswatkum']['interacted'];
                $replied += $spoke;
                $reached += max((int) $row['funnel']['contacted'], $spoke);
            } else {
                $replied += $row['replied'];
                $reached += $row['reached'];
            }
        }

        return [
            'bots' => $rows,
            'totals' => $totals,
            'shares' => array_map(fn (int $count): int => $this->percent($count, $votingReached), $totals),
            'contacts' => $contacts,
            'sent' => $sent,
            'ai_messages' => $this->aiMessages($allIds),
            'errors' => $errors,
            'replied' => $replied,
            'reached' => $reached,
            'reply_rate' => $this->percent($replied, $reached),
            'certain_share' => $this->percent($totals[MansourInsightService::GROUP_CERTAIN], $votingReplied),
            'active_bots' => count(array_filter($rows, fn (array $row): bool => $row['active'])),
            'apps' => $appsAnalytics,
            'activity' => $this->activity($allIds),
            'recent' => $this->recent(array_values(array_unique(array_merge($votingIds, $dimaIds, $inviteIds))), $groupOf, $bots),
            'generated_at' => CarbonImmutable::now(self::TIMEZONE),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{contacts:int, sent:int, errors:int, last_sent:?CarbonImmutable}>
     */
    private function contactStats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stats = [];
        $rows = MansourContact::query()
            ->whereIn('instance_id', $ids)
            ->groupBy('instance_id')
            ->selectRaw('instance_id, COUNT(*) as contacts, SUM(CASE WHEN triggered_at IS NOT NULL THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN last_error IS NOT NULL THEN 1 ELSE 0 END) as errors, MAX(triggered_at) as last_sent')
            ->get();

        foreach ($rows as $row) {
            $stats[(int) $row->instance_id] = [
                'contacts' => (int) $row->contacts,
                'sent' => (int) $row->sent,
                'errors' => (int) $row->errors,
                'last_sent' => $row->last_sent ? CarbonImmutable::parse($row->last_sent, 'UTC')->setTimezone(self::TIMEZONE) : null,
            ];
        }

        return $stats;
    }

    /**
     * Assistant messages the bots actually sent, excluding the test chat.
     *
     * @param  list<int>  $ids
     */
    private function aiMessages(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return ChatbotMessage::query()
            ->join('ai_chatbot_conversations as c', 'c.id', '=', 'ai_chatbot_messages.conversation_id')
            ->whereIn('c.instance_id', $ids)
            ->where('c.channel', '!=', ChatbotConversation::CHANNEL_TEST)
            ->where('ai_chatbot_messages.role', 'assistant')
            ->count();
    }

    /**
     * Daily sends vs. incoming messages, plus the hours people reply, in local time.
     *
     * @param  list<int>  $ids
     * @return array{days: list<array{label:string, date:string, sent:int, replies:int}>, hours: list<int>, peak_hour: ?int, max_day:int, max_hour:int}
     */
    private function activity(array $ids): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $from = $today->subDays(self::DAYS - 1);
        $fromUtc = $from->setTimezone('UTC');

        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $from->addDays($i);
            $days[$day->toDateString()] = [
                'label' => $day->format('d/m'),
                'date' => $day->toDateString(),
                'sent' => 0,
                'replies' => 0,
            ];
        }
        $hours = array_fill(0, 24, 0);

        if ($ids !== []) {
            [$dateSql, $hourSql] = $this->bucketSql();

            $sent = MansourContact::query()
                ->whereIn('instance_id', $ids)
                ->where('triggered_at', '>=', $fromUtc)
                ->groupByRaw($dateSql('triggered_at').', '.$hourSql('triggered_at'))
                ->selectRaw($dateSql('triggered_at').' as d, '.$hourSql('triggered_at').' as h, COUNT(*) as c')
                ->get();
            foreach ($sent as $row) {
                $local = $this->localBucket((string) $row->d, (int) $row->h);
                if (isset($days[$local->toDateString()])) {
                    $days[$local->toDateString()]['sent'] += (int) $row->c;
                }
            }

            $replies = ChatbotMessage::query()
                ->join('ai_chatbot_conversations as c', 'c.id', '=', 'ai_chatbot_messages.conversation_id')
                ->whereIn('c.instance_id', $ids)
                ->where('c.channel', '!=', ChatbotConversation::CHANNEL_TEST)
                ->whereNull('c.campaign_id')
                ->where('ai_chatbot_messages.role', 'user')
                ->where('ai_chatbot_messages.created_at', '>=', $fromUtc)
                ->groupByRaw($dateSql('ai_chatbot_messages.created_at').', '.$hourSql('ai_chatbot_messages.created_at'))
                ->selectRaw($dateSql('ai_chatbot_messages.created_at').' as d, '.$hourSql('ai_chatbot_messages.created_at').' as h, COUNT(*) as c')
                ->get();
            foreach ($replies as $row) {
                $local = $this->localBucket((string) $row->d, (int) $row->h);
                if (isset($days[$local->toDateString()])) {
                    $days[$local->toDateString()]['replies'] += (int) $row->c;
                }
                $hours[(int) $local->format('G')] += (int) $row->c;
            }
        }

        $days = array_values($days);
        $maxHour = max($hours);

        return [
            'days' => $days,
            'hours' => $hours,
            'peak_hour' => $maxHour > 0 ? (int) array_search($maxHour, $hours, true) : null,
            'max_day' => max(1, ...array_map(fn (array $day): int => max($day['sent'], $day['replies']), $days)),
            'max_hour' => max(1, $maxHour),
        ];
    }

    /**
     * @return array{0: callable(string): string, 1: callable(string): string}
     */
    private function bucketSql(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return [
                fn (string $column): string => "date($column)",
                fn (string $column): string => "CAST(strftime('%H', $column) AS INTEGER)",
            ];
        }

        return [
            fn (string $column): string => "DATE($column)",
            fn (string $column): string => "HOUR($column)",
        ];
    }

    private function localBucket(string $date, int $hour): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.sprintf('%02d', $hour).':00:00', 'UTC')->setTimezone(self::TIMEZONE);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $groupOf
     * @param  Collection<int, ChatbotInstance>  $bots
     * @return list<array<string, mixed>>
     */
    private function recent(array $ids, array $groupOf, Collection $bots): array
    {
        if ($ids === []) {
            return [];
        }

        $conversations = ChatbotConversation::query()
            ->whereIn('instance_id', $ids)
            ->customerFacing()
            ->where(function ($query): void {
                $query->whereNull('external_chat_id')
                    ->orWhere('external_chat_id', 'not like', '%@g.us');
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ai_chatbot_messages')
                    ->whereColumn('ai_chatbot_messages.conversation_id', 'ai_chatbot_conversations.id')
                    ->where('ai_chatbot_messages.role', 'user');
            })
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'instance_id', 'contact_name', 'contact_phone', 'external_chat_id', 'title', 'updated_at']);

        if ($conversations->isEmpty()) {
            return [];
        }

        $lastUserMessage = [];
        ChatbotMessage::query()
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->where('role', 'user')
            ->orderByDesc('id')
            ->get(['conversation_id', 'message', 'created_at'])
            ->each(function (ChatbotMessage $message) use (&$lastUserMessage): void {
                $lastUserMessage[(int) $message->conversation_id] ??= $message;
            });

        $botsById = $bots->keyBy('id');

        return $conversations->map(function (ChatbotConversation $conversation) use ($lastUserMessage, $groupOf, $botsById): array {
            $message = $lastUserMessage[(int) $conversation->id] ?? null;
            $at = $message?->created_at ?? $conversation->updated_at;

            return [
                'conversation' => $conversation,
                'bot' => $botsById->get($conversation->instance_id),
                'name' => $conversation->displayName(),
                'group' => $groupOf[(int) $conversation->id] ?? null,
                'snippet' => mb_strimwidth(trim((string) ($message?->message ?? '')), 0, 90, '…'),
                'at' => $at ? CarbonImmutable::parse($at)->setTimezone(self::TIMEZONE) : null,
            ];
        })->all();
    }

    /**
     * @return array{
     *     contacted:int,
     *     aswatkum: array{downloaded:int,interacted:int,waiting_registration:int,waiting_delegate:int},
     *     muwahada: array{downloaded:int,interacted:int,waiting_registration:int,waiting_delegate:int}
     * }
     */
    private function blankFunnel(): array
    {
        $counts = ['downloaded' => 0, 'interacted' => 0, 'waiting_registration' => 0, 'waiting_delegate' => 0];

        return [
            'contacted' => 0,
            'aswatkum' => $counts,
            'muwahada' => $counts,
        ];
    }

    private function percent(int $count, int $total): int
    {
        return $total > 0 ? (int) round(($count / $total) * 100) : 0;
    }
}
