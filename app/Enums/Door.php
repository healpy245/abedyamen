<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Http\Request;

/**
 * The workspace doors and their sub doors, as the sidebar and the door tabs show them.
 *
 * The users management page, the locked state and the route gate all read from here. A route opens
 * to a door or sub door through routeRules(), which EnsureDoorIsOpen checks on every web request.
 */
enum Door: string
{
    case Overview = 'overview';
    case Support = 'support';
    case Development = 'development';
    case Agents = 'agents';
    case Finance = 'finance';
    case Form = 'form';

    public function label(): string
    {
        return match ($this) {
            self::Overview => __('app.hub_overview'),
            self::Support => __('app.hub_support_nav'),
            self::Development => __('app.hub_dev'),
            self::Agents => __('app.hub_agents_nav'),
            self::Finance => __('app.hub_finance'),
            self::Form => __('projects.form.label'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Overview => 'grid',
            self::Support => 'headset',
            self::Development => 'code',
            self::Agents => 'bot',
            self::Finance => 'chart',
            self::Form => 'receipt',
        };
    }

    /**
     * The project this door stands for, when access to it was already kept per project.
     */
    public function project(): ?Project
    {
        return match ($this) {
            self::Support => Project::KamanSupport,
            self::Development => Project::AppDevelopment,
            self::Form => Project::Form,
            default => null,
        };
    }

    public static function forProject(Project $project): self
    {
        return match ($project) {
            Project::KamanSupport => self::Support,
            Project::AppDevelopment => self::Development,
            Project::Form => self::Form,
        };
    }

    /**
     * The sub doors, in tab order. The first one a user can open is where the door takes them.
     *
     * @return array<string, array{label: string, icon: string, route: ?string, params?: array<string, string>}>
     */
    public function subDoors(): array
    {
        return match ($this) {
            self::Overview => [
                'dashboard' => ['label' => __('app.overview_dashboard'), 'icon' => 'grid', 'route' => 'workspace.overview', 'params' => ['page' => 'dashboard']],
                'restaurants' => ['label' => __('app.overview_restaurants'), 'icon' => 'store', 'route' => 'workspace.overview', 'params' => ['page' => 'restaurants']],
                'card_finance' => ['label' => __('access.card_finance'), 'icon' => 'chart', 'route' => null],
                'card_support' => ['label' => __('access.card_support'), 'icon' => 'headset', 'route' => null],
            ],
            self::Support => [
                'live' => ['label' => __('kaman-support.nav_live'), 'icon' => 'monitor', 'route' => 'kaman-support.index'],
                'logs' => ['label' => __('kaman-support.nav_logs'), 'icon' => 'logs', 'route' => 'kaman-support.logs'],
                'menu' => ['label' => __('kaman-support.nav_menu'), 'icon' => 'spark', 'route' => 'kaman-support.menu'],
                'tickets' => ['label' => __('kaman-support.nav_tickets'), 'icon' => 'ticket', 'route' => 'kaman-support.inbox'],
            ],
            self::Development => [
                'reports' => ['label' => __('app-development.nav.reports'), 'icon' => 'chart', 'route' => 'app-development.reports.index'],
                'tickets' => ['label' => __('app-development.nav.tickets'), 'icon' => 'ticket', 'route' => 'app-development.tickets.index'],
                'tasks' => ['label' => __('app-development.nav.tasks'), 'icon' => 'logs', 'route' => 'app-development.tasks.index'],
                'releases' => ['label' => __('app-development.nav.releases'), 'icon' => 'box', 'route' => 'app-development.releases.index'],
            ],
            self::Agents => [
                'leads' => ['label' => __('app.agents_leads'), 'icon' => 'target', 'route' => 'workspace.agents', 'params' => ['page' => 'leads']],
                'calendar' => ['label' => __('app.agents_calendar'), 'icon' => 'calendar', 'route' => 'workspace.agents', 'params' => ['page' => 'calendar']],
                'sales' => ['label' => __('app.agents_sales'), 'icon' => 'receipt', 'route' => 'workspace.agents.sales'],
                'packages' => ['label' => __('app.agents_packages'), 'icon' => 'box', 'route' => 'workspace.agents.packages'],
            ],
            self::Finance => [
                'dashboard' => ['label' => __('finance.nav.dashboard'), 'icon' => 'chart', 'route' => 'workspace.finance.dashboard'],
                'clients' => ['label' => __('finance.nav.clients'), 'icon' => 'users', 'route' => 'workspace.finance.clients'],
                'deals' => ['label' => __('finance.nav.deals'), 'icon' => 'receipt', 'route' => 'workspace.finance.deals'],
                'storage' => ['label' => __('finance.nav.storage'), 'icon' => 'box', 'route' => 'workspace.finance.storage'],
            ],
            self::Form => [],
        };
    }

    public function hasSubDoor(string $key): bool
    {
        return array_key_exists($key, $this->subDoors());
    }

    public function url(?string $subDoor = null): string
    {
        if ($subDoor !== null && $this->hasSubDoor($subDoor)) {
            $sub = $this->subDoors()[$subDoor];
            if ($sub['route'] !== null) {
                return route($sub['route'], $sub['params'] ?? []);
            }
        }

        return match ($this) {
            self::Form => route('form.index'),
            default => $this->url((string) array_key_first($this->subDoors())),
        };
    }

    /**
     * Which door and sub door a route belongs to. A null sub door means any open sub door may use it.
     * First match wins, so the narrower names come first. Form and the chatbots are gated by `project:`.
     *
     * @return list<array{0: list<string>, 1: self, 2: ?string}>
     */
    public static function routeRules(): array
    {
        return [
            // The phone app's reads, behind the same doors as the pages they mirror.
            [['mobile.overview'], self::Overview, 'dashboard'],
            [['mobile.restaurants'], self::Overview, 'restaurants'],
            [['mobile.finance.dashboard'], self::Finance, 'dashboard'],
            [['mobile.finance.clients', 'mobile.finance.clients.*'], self::Finance, 'clients'],
            [['mobile.finance.deals'], self::Finance, 'deals'],
            [['mobile.finance.storage'], self::Finance, 'storage'],
            [['mobile.agents.sales', 'mobile.agents.sales.*'], self::Agents, 'sales'],
            [['mobile.agents.packages'], self::Agents, 'packages'],
            [['mobile.agents.leads'], self::Agents, 'leads'],
            [['mobile.agents.calendar'], self::Agents, 'calendar'],
            [['mobile.development.reports'], self::Development, 'reports'],
            [['mobile.development.tickets', 'mobile.development.tickets.*'], self::Development, 'tickets'],
            [['mobile.development.tasks', 'mobile.development.tasks.*'], self::Development, 'tasks'],
            [['mobile.development.releases'], self::Development, 'releases'],
            [['mobile.development.options'], self::Development, null],

            [['today', 'design.*'], self::Overview, 'dashboard'],

            [['kaman-support.logos'], self::Support, null],
            [['kaman-support.logs', 'kaman-support.logs.*', 'kaman-support.failed', 'kaman-support.failed.*'], self::Support, 'logs'],
            [['kaman-support.menu', 'kaman-support.menu.*'], self::Support, 'menu'],
            [['kaman-support.inbox', 'kaman-support.inbox.*', 'kaman-support.show', 'kaman-support.tickets.*', 'kaman-support.close', 'kaman-support.escalate', 'kaman-support.api.*'], self::Support, 'tickets'],
            [['kaman-support.*'], self::Support, 'live'],

            [['app-development.reports.*'], self::Development, 'reports'],
            [['app-development.tickets.tasks.*', 'app-development.tasks.*', 'app-development.time-entries.*'], self::Development, 'tasks'],
            [['app-development.releases.*'], self::Development, 'releases'],
            [['app-development.index', 'app-development.tickets.*', 'app-development.uploads.*'], self::Development, 'tickets'],
            [['app-development.*'], self::Development, null],

            [['workspace.agents.sales', 'workspace.agents.sales.*'], self::Agents, 'sales'],
            [['workspace.agents.packages'], self::Agents, 'packages'],
            [['workspace.agents.leads.*'], self::Agents, 'leads'],
            [['workspace.agents.calendar.*'], self::Agents, 'calendar'],

            [['workspace.finance.deals.device-payment'], self::Finance, 'clients'],
            [['workspace.finance.clients', 'workspace.finance.clients.*', 'workspace.finance.payments.*', 'workspace.finance.invoices.*'], self::Finance, 'clients'],
            [['workspace.finance.deals', 'workspace.finance.deals.*', 'workspace.finance.notifications.*'], self::Finance, 'deals'],
            [['workspace.finance.storage', 'workspace.finance.storage.*'], self::Finance, 'storage'],
            [['workspace.finance.dashboard'], self::Finance, 'dashboard'],
            [['workspace.finance.*'], self::Finance, null],
        ];
    }

    /**
     * @return array{0: self, 1: ?string}|null
     */
    public static function forRequest(Request $request): ?array
    {
        // One route shows these sub doors; the page in the address picks which.
        if ($request->routeIs('workspace.overview')) {
            return [self::Overview, $request->route('page') === 'restaurants' ? 'restaurants' : 'dashboard'];
        }
        if ($request->routeIs('workspace.agents')) {
            // /agents/clients only redirects to the Restaurants page, which keeps its own lock.
            return $request->route('page') === 'clients'
                ? null
                : [self::Agents, $request->route('page') === 'calendar' ? 'calendar' : 'leads'];
        }

        foreach (self::routeRules() as [$names, $door, $subDoor]) {
            if ($request->routeIs(...$names)) {
                return [$door, $subDoor];
            }
        }

        return null;
    }
}
