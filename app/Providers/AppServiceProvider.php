<?php

namespace App\Providers;

use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use App\Policies\AppDevelopmentReleasePolicy;
use App\Policies\AppDevelopmentTaskPolicy;
use App\Policies\AppDevelopmentTicketPolicy;
use App\Policies\AppDevelopmentTimeEntryPolicy;
use App\Services\Malan\Contracts\BankTransferProofVerifier;
use App\Services\Malan\Contracts\ChargeSavedPaymentMethod;
use App\Services\Malan\Contracts\CheckPaymentStatus;
use App\Services\Malan\Contracts\CreateOneTimePaymentLink;
use App\Services\Malan\Contracts\RequestServiceReactivation;
use App\Services\Malan\Payment\PendingChargeSavedPaymentMethod;
use App\Services\Malan\Payment\PendingCheckPaymentStatus;
use App\Services\Malan\Payment\PendingCreateOneTimePaymentLink;
use App\Services\Malan\Payment\PendingRequestServiceReactivation;
use App\Services\Malan\Proof\OpenAiVisionBankTransferProofVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ChargeSavedPaymentMethod::class, PendingChargeSavedPaymentMethod::class);
        $this->app->bind(CreateOneTimePaymentLink::class, PendingCreateOneTimePaymentLink::class);
        $this->app->bind(CheckPaymentStatus::class, PendingCheckPaymentStatus::class);
        $this->app->bind(RequestServiceReactivation::class, PendingRequestServiceReactivation::class);
        $this->app->bind(BankTransferProofVerifier::class, OpenAiVisionBankTransferProofVerifier::class);
    }

    public function boot(): void
    {
        Gate::policy(AppDevelopmentTicket::class, AppDevelopmentTicketPolicy::class);
        Gate::policy(AppDevelopmentRelease::class, AppDevelopmentReleasePolicy::class);
        Gate::policy(AppDevelopmentTask::class, AppDevelopmentTaskPolicy::class);
        Gate::policy(AppDevelopmentTimeEntry::class, AppDevelopmentTimeEntryPolicy::class);

        View::composer('app-development.partials.nav', \App\View\Composers\AppDevelopmentNavComposer::class);
        View::composer('app-development.layouts.project', \App\View\Composers\AppDevelopmentTimerComposer::class);
        View::composer('partials.topbar', \App\View\Composers\NotificationComposer::class);

        $perMinute = max(1, (int) config('kaman-support.ticket_rate_per_minute', 30));
        RateLimiter::for('kaman-support-tickets', function (Request $request) use ($perMinute) {
            $restaurant = strtolower(trim((string) $request->input('restaurant', 'unknown')));

            return [
                Limit::perMinute($perMinute)->by('ip:'.$request->ip()),
                Limit::perMinute($perMinute)->by('restaurant:'.$restaurant),
            ];
        });

        $livePerMinute = max(1, (int) config('kaman-support.live_rate_per_minute', 180));
        RateLimiter::for('kaman-support-live', function (Request $request) use ($livePerMinute) {
            $device = strtolower(trim((string) $request->input('deviceId', $request->input('device_id', 'unknown'))));

            return [
                Limit::perMinute($livePerMinute)->by('live-ip:'.$request->ip()),
                Limit::perMinute($livePerMinute)->by('live-device:'.$device),
            ];
        });

        if ($this->app->environment(['local', 'testing'])) {
            Http::globalOptions([
                'verify' => false,
                'curl' => [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ],
            ]);
        }
    }
}
