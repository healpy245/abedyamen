<?php

namespace App\Providers;

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
use Illuminate\Support\Facades\Http;
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
