<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeVision;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OfficeVisionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_officevision_pairs_devices_and_serves_events_and_clips(): void
    {
        config(['officevision.pairing_key' => '']);

        $this->postJson('/api/officevision/pair', [
            'pairing_key' => 'office-pair',
            'name' => 'Office PC',
            'role' => 'device',
        ])->assertStatus(503);

        config(['officevision.pairing_key' => 'office-pair']);

        $this->postJson('/api/officevision/pair', [
            'pairing_key' => 'wrong',
            'name' => 'Office PC',
            'role' => 'device',
        ])->assertForbidden();

        $device = $this->postJson('/api/officevision/pair', [
            'pairing_key' => 'office-pair',
            'name' => 'Office PC',
            'role' => 'device',
        ])->assertCreated()->json();

        $viewer = $this->postJson('/api/officevision/pair', [
            'pairing_key' => 'office-pair',
            'name' => 'Phone',
            'role' => 'viewer',
        ])->assertCreated()->json();

        $this->getJson('/api/officevision/events')->assertUnauthorized();

        $this->post('/api/officevision/events', [
            'event_uid' => 'evt-1',
            'camera_name' => 'Main Entrance',
            'event_type' => 'enter',
            'occurred_at' => '2026-10-10T09:15:00Z',
            'confidence' => 0.91,
        ], [
            'Authorization' => 'Bearer '.$viewer['token'],
            'Accept' => 'application/json',
        ])->assertForbidden();

        $created = $this->post('/api/officevision/events', [
            'event_uid' => 'evt-1',
            'camera_name' => 'Main Entrance',
            'event_type' => 'enter',
            'occurred_at' => '2026-10-10T09:15:00Z',
            'confidence' => 0.91,
            'clip' => UploadedFile::fake()->create('clip.mp4', 20, 'video/mp4'),
        ], [
            'Authorization' => 'Bearer '.$device['token'],
            'Accept' => 'application/json',
        ])->assertCreated();

        $this->post('/api/officevision/events', [
            'event_uid' => 'evt-1',
            'camera_name' => 'Main Entrance',
            'event_type' => 'enter',
            'occurred_at' => '2026-10-10T09:15:00Z',
        ], [
            'Authorization' => 'Bearer '.$device['token'],
            'Accept' => 'application/json',
        ])->assertOk();

        $events = $this->getJson('/api/officevision/events', [
            'Authorization' => 'Bearer '.$viewer['token'],
        ])->assertOk()->json('events');

        $this->assertCount(1, $events);
        $this->assertNull($events[0]['worker_name']);
        $this->assertSame('enter', $events[0]['event_type']);
        $this->assertSame('Main Entrance', $events[0]['camera_name']);
        $this->assertSame($created->json('id'), $events[0]['id']);

        $this->get('/api/officevision/events/'.$events[0]['id'].'/clip', [
            'Authorization' => 'Bearer '.$viewer['token'],
        ])->assertOk();

        $this->postJson('/api/officevision/heartbeat', [
            'stream_base' => 'http://203.0.113.5:8889',
            'stream_user' => 'viewer',
            'stream_password' => 'secret',
            'cameras' => [
                ['name' => 'Main Entrance', 'path' => 'cam0', 'online' => true, 'persons' => 1],
            ],
        ], [
            'Authorization' => 'Bearer '.$device['token'],
        ])->assertOk();

        $cameras = $this->getJson('/api/officevision/cameras', [
            'Authorization' => 'Bearer '.$viewer['token'],
        ])->assertOk()->json('cameras');

        $this->assertSame('Main Entrance', $cameras[0]['name']);
        $this->assertTrue($cameras[0]['online']);

        $streams = $this->getJson('/api/officevision/streams', [
            'Authorization' => 'Bearer '.$viewer['token'],
        ])->assertOk()->json();

        $this->assertSame('http://203.0.113.5:8889', $streams['stream_base']);
        $this->assertSame('viewer', $streams['stream_user']);
        $this->assertSame('secret', $streams['stream_password']);
    }
}
