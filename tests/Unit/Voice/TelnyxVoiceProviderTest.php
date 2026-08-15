<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Providers\TelnyxVoiceProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelnyxVoiceProviderTest extends TestCase
{
    public function test_missing_configuration_keys_lists_required_env_names(): void
    {
        config([
            'voice.providers.telnyx.api_key' => null,
            'voice.providers.telnyx.connection_id' => null,
            'voice.providers.telnyx.phone_number' => null,
            'voice.providers.telnyx.public_key' => null,
        ]);

        $provider = new TelnyxVoiceProvider;

        $this->assertContains('TELNYX_API_KEY', $provider->missingConfigurationKeys());
        $this->assertContains('TELNYX_CONNECTION_ID', $provider->missingConfigurationKeys());
        $this->assertFalse($provider->isConfigured());
    }

    public function test_configured_provider_can_attempt_http_action(): void
    {
        Http::fake([
            'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
        ]);

        config([
            'voice.providers.telnyx.api_key' => 'test-key',
            'voice.providers.telnyx.connection_id' => 'conn',
            'voice.providers.telnyx.phone_number' => '+15551234567',
            'voice.providers.telnyx.public_key' => 'public-key',
        ]);

        $provider = new TelnyxVoiceProvider;
        $result = $provider->answerCall('v3:call-1');

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
    }
}
