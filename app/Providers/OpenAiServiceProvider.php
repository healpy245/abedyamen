<?php

namespace App\Providers;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use OpenAI;
use OpenAI\Client;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Exceptions\ApiKeyIsMissing;

class OpenAiServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(ClientContract::class, $this->createOpenAiClientClosure());
        $this->app->alias(ClientContract::class, Client::class);
        $this->app->alias(ClientContract::class, 'openai');
    }

    public function provides(): array
    {
        return [ClientContract::class, Client::class, 'openai'];
    }

    private function createOpenAiClientClosure(): \Closure
    {
        return static function (): Client {
            $apiKey = config('openai.api_key');
            $organization = config('openai.organization');
            $project = config('openai.project');
            $baseUri = config('openai.base_uri');

            if (!is_string($apiKey) || ($organization !== null && !is_string($organization))) {
                throw ApiKeyIsMissing::create();
            }

            $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);
            if (app()->environment(['local', 'testing'])) {
                $sslVerify = false;
            }

            $guzzleOptions = [
                'timeout' => config('openai.request_timeout', 30),
                'verify' => $sslVerify,
                'curl' => $sslVerify ? [] : [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ],
            ];

            $client = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withOrganization($organization)
                ->withHttpClient(new GuzzleClient($guzzleOptions));

            if (is_string($project)) {
                $client->withProject($project);
            }

            if (is_string($baseUri)) {
                $client->withBaseUri($baseUri);
            }

            return $client->make();
        };
    }
}
