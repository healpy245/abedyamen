<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Realtime\RealtimeCallsClient;
use App\Support\Voice\RealtimeSdpTracer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Tests\TestCase;

class RealtimeCallsClientTest extends TestCase
{
    public function test_exchange_sdp_sends_plain_multipart_parts_without_manual_content_type(): void
    {
        $answer = "v=0\r\no=- 0 0 IN IP4 0.0.0.0\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n";
        $mock = new MockHandler([
            new Response(201, ['Content-Type' => 'application/sdp'], $answer),
        ]);

        $container = [];
        $history = Middleware::history($container);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $guzzle = new Client(['handler' => $stack, 'http_errors' => false]);
        $client = new RealtimeCallsClient($guzzle);

        $offer = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\na=rtpmap:111 opus/48000/2\r\n";
        $session = [
            'type' => 'realtime',
            'model' => 'gpt-realtime',
            'audio' => ['output' => ['voice' => 'marin']],
        ];

        $response = $client->exchangeSdp(
            'https://api.openai.com/v1',
            'test-key',
            $offer,
            $session,
            false,
        );

        $this->assertTrue($response->successful());
        $this->assertSame($answer, $response->body);
        $this->assertSame('application/sdp', $response->contentType);

        /** @var \Psr\Http\Message\RequestInterface $request */
        $request = $container[0]['request'];
        $wireBody = (string) $request->getBody();
        $contentType = $request->getHeaderLine('Content-Type');

        $this->assertStringContainsString('multipart/form-data', $contentType);
        $this->assertStringContainsString('boundary=', $contentType);
        $this->assertStringContainsString('name="sdp"', $wireBody);
        $this->assertStringContainsString('name="session"', $wireBody);
        $this->assertStringContainsString($offer, $wireBody);
        $this->assertStringContainsString('"model":"gpt-realtime"', $wireBody);
        $this->assertStringContainsString('Content-Type: application/sdp', $wireBody);
        $this->assertStringContainsString('Content-Type: application/json', $wireBody);
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/sdp', $request->getHeaderLine('Accept'));
    }

    public function test_request_diagnostics_middleware_does_not_consume_non_seekable_body(): void
    {
        $offer = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n";
        $sessionJson = '{"type":"realtime","model":"gpt-realtime"}';
        $receivedBody = null;

        $mock = new MockHandler([
            function ($request) use (&$receivedBody) {
                $receivedBody = (string) $request->getBody();

                return new Response(201, ['Content-Type' => 'application/sdp'], "v=0\r\n");
            },
        ]);

        $stack = HandlerStack::create($mock);
        RealtimeCallsClient::registerRequestDiagnosticsMiddleware($stack);

        $client = new RealtimeCallsClient(new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]));

        $response = $client->exchangeSdp(
            'https://api.openai.com/v1',
            'test-key',
            $offer,
            json_decode($sessionJson, true),
            false,
        );

        $this->assertTrue($response->successful());
        $this->assertIsString($receivedBody);
        $this->assertStringContainsString('name="sdp"', $receivedBody);
        $this->assertStringContainsString('v=0', $receivedBody);
        $this->assertStringContainsString($offer, $receivedBody);
    }

    public function test_trace_psr7_request_does_not_consume_non_seekable_stream(): void
    {
        $payload = 'Content-Disposition: form-data; name="sdp"'."\r\n\r\n".'v=0'."\r\n".'m=audio 9';
        $stream = new NoSeekStream(Utils::streamFor($payload));
        $request = new Request(
            'POST',
            'https://api.openai.com/v1/realtime/calls',
            ['Content-Type' => 'multipart/form-data; boundary=test'],
            $stream,
        );

        RealtimeSdpTracer::tracePsr7Request($request);

        $body = $request->getBody();
        $this->assertFalse($body->isSeekable());
        $this->assertTrue($body->isReadable());
        $this->assertStringContainsString('v=0', (string) $body);
    }
}
