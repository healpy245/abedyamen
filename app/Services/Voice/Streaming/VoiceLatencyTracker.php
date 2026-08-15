<?php

declare(strict_types=1);

namespace App\Services\Voice\Streaming;

use Illuminate\Support\Facades\Log;

class VoiceLatencyTracker
{
    /** @var array<string, float> */
    private array $marks = [];

    /** @var array<string, int> */
    private array $durations = [];

    public function __construct(
        public readonly string $turnId,
    ) {
        $this->mark('turn_start');
    }

    public static function create(?string $turnId = null): self
    {
        return new self($turnId ?: bin2hex(random_bytes(8)));
    }

    public function mark(string $name): void
    {
        $this->marks[$name] = microtime(true);
    }

    public function durationMs(string $from, string $to): int
    {
        if (! isset($this->marks[$from], $this->marks[$to])) {
            return 0;
        }

        return (int) round(($this->marks[$to] - $this->marks[$from]) * 1000);
    }

    public function set(string $key, int $ms): void
    {
        $this->durations[$key] = max(0, $ms);
    }

    public function add(string $key, int $ms): void
    {
        $this->durations[$key] = ($this->durations[$key] ?? 0) + max(0, $ms);
    }

    /**
     * @return array<string, int|string>
     */
    public function snapshot(): array
    {
        $ttfa = $this->durations['time_to_first_audio']
            ?? (isset($this->marks['audio_first_ready'])
                ? $this->durationMs('turn_start', 'audio_first_ready')
                : 0);

        return array_merge([
            'turn_id' => $this->turnId,
            'time_to_first_audio' => $ttfa,
        ], $this->durations);
    }

    public function flushLog(string $channel = 'voice_latency'): void
    {
        $enabled = filter_var(config('voice.latency.log', true), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return;
        }

        Log::channel($this->resolveChannel($channel))->info('[VOICE LATENCY]', $this->snapshot());
    }

    private function resolveChannel(string $preferred): string
    {
        $channels = config('logging.channels', []);
        if (is_array($channels) && array_key_exists($preferred, $channels)) {
            return $preferred;
        }

        return config('logging.default', 'stack');
    }
}
