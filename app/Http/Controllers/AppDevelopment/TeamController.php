<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\UpdateTeamMemberRequest;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Services\AppDevelopment\AppDevelopmentWhatsAppNotifyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class TeamController extends Controller
{
    public function __construct(
        private readonly AppDevelopmentWhatsAppNotifyService $whatsapp,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->isAppDevelopmentAdmin(), 403);

        $members = AppDevelopmentMember::query()
            ->with('user:id,name,email')
            ->orderBy('role')
            ->get()
            ->sortBy(fn (AppDevelopmentMember $member): string => mb_strtolower((string) $member->user?->name));

        return view('app-development.team.index', [
            'members' => $members,
        ]);
    }

    public function update(UpdateTeamMemberRequest $request, AppDevelopmentMember $member): RedirectResponse
    {
        $member->forceFill([
            'phone' => $request->validated('phone') ?: null,
            'whatsapp_notifications_enabled' => $request->boolean('whatsapp_notifications_enabled'),
        ])->save();

        $password = $request->validated('password');
        if (is_string($password) && $password !== '' && $member->user) {
            $member->user->forceFill([
                'password' => Hash::make($password),
            ])->save();
        }

        $this->whatsapp->syncWorkerPhonesOntoKamanBotIgnoreList();

        return back()->with('success', __('app-development.flash.team_member_updated'));
    }

    public function notifyOpen(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAccessProject(\App\Enums\Project::AppDevelopment), 403);

        $userIds = $this->selectedUserIds($request);
        if ($userIds === []) {
            return back()->with('error', __('app-development.errors.whatsapp_no_recipients'));
        }

        return $this->dispatchNotify(
            fn (): array => $this->whatsapp->notifyOpenTickets($userIds),
            'open',
        );
    }

    public function notifyQa(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAccessProject(\App\Enums\Project::AppDevelopment), 403);

        $userIds = $this->selectedUserIds($request);
        if ($userIds === []) {
            return back()->with('error', __('app-development.errors.whatsapp_no_recipients'));
        }

        return $this->dispatchNotify(
            fn (): array => $this->whatsapp->notifyQaTickets($userIds),
            'qa',
        );
    }

    /**
     * @return list<int>
     */
    private function selectedUserIds(Request $request): array
    {
        $ids = $request->input('user_ids', []);
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  callable(): array{sent: int, skipped: int, failed: int, count: int, recipients: list<string>}  $action
     */
    private function dispatchNotify(callable $action, string $audience): RedirectResponse
    {
        try {
            $result = $action();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('app-development.errors.whatsapp_notify_failed'));
        }

        if ($result['sent'] === 0 && $result['failed'] === 0) {
            return back()->with('error', __('app-development.errors.whatsapp_no_recipients'));
        }

        $key = $audience === 'qa'
            ? 'app-development.flash.whatsapp_qa_sent'
            : 'app-development.flash.whatsapp_open_sent';

        return back()->with('success', __($key, [
            'sent' => $result['sent'],
            'count' => $result['count'],
        ]));
    }
}
