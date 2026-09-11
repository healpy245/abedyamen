<?php

declare(strict_types=1);

namespace App\Services\Haat;

use App\Exceptions\FormWorkflowPausedException;
use App\Services\Form\FormWorkflowRunService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class KamanHttp
{
    private static ?int $runId = null;

    /** @var callable(string, string, array): void|null */
    private static $progress = null;

    private static ?float $lastAt = null;

    private static ?int $generation = null;

    public static function bind(int|string|null $runId, ?callable $progress = null, ?int $generation = null): void
    {
        self::$runId = $runId !== null && $runId !== '' ? (int) $runId : null;
        self::$progress = $progress;
        self::$lastAt = null;
        self::$generation = $generation;
    }

    public static function unbind(): void
    {
        self::$runId = null;
        self::$progress = null;
        self::$lastAt = null;
        self::$generation = null;
    }

    public static function pending(int $timeout = 15): PendingRequest
    {
        self::guardPause();
        self::pace();

        $timeout = max(5, $timeout);
        $http = Http::timeout($timeout)
            ->connectTimeout(min(6, $timeout))
            ->acceptJson();
        if (! config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    /**
     * @param  callable(): Response  $send
     */
    public static function send(callable $send): Response
    {
        $response = null;
        $max = 5;
        for ($attempt = 1; $attempt <= $max; $attempt++) {
            self::guardPause();
            try {
                $response = $send();
            } catch (ConnectionException $e) {
                $progress = self::$progress;
                $progress && $progress('throttle', 'Kaman timed out. Retrying '.$attempt.'/'.$max.'...', [
                    'status' => 'run',
                    'attempt' => $attempt,
                ]);
                if ($attempt >= $max) {
                    throw $e;
                }
                self::sleepPauseAware(2.0);

                continue;
            }
            if ($response->status() !== 429) {
                return $response;
            }

            $header = $response->header('Retry-After');
            $seconds = is_numeric($header) ? (int) $header : min(15, max(3, 4 * $attempt));
            $seconds = min(20, max(1, $seconds));
            $progress = self::$progress;
            $progress && $progress('throttle', 'Kaman rate limit (HTTP 429). Waiting '.$seconds.'s then retrying ('.$attempt.'/'.$max.')...', [
                'status' => 'run',
                'attempt' => $attempt,
                'wait' => $seconds,
            ]);
            self::sleepPauseAware((float) $seconds);
        }

        return $response;
    }

    public static function guardPause(): void
    {
        if (self::$runId === null) {
            return;
        }

        $command = app(FormWorkflowRunService::class)->command(self::$runId);
        if ($command === FormWorkflowRunService::COMMAND_PAUSE) {
            throw new FormWorkflowPausedException('Workflow paused.');
        }
        if (self::$generation !== null) {
            $current = app(FormWorkflowRunService::class)->workerGeneration(self::$runId);
            if ($current !== self::$generation) {
                throw new FormWorkflowPausedException('Workflow paused.');
            }
        }
    }

    private static function pace(): void
    {
        $gap = self::minGapSeconds();
        if ($gap <= 0) {
            self::$lastAt = microtime(true);

            return;
        }

        if (self::$lastAt !== null) {
            $elapsed = microtime(true) - self::$lastAt;
            if ($elapsed < $gap) {
                self::sleepPauseAware($gap - $elapsed);
            }
        }

        self::$lastAt = microtime(true);
    }

    private static function minGapSeconds(): float
    {
        if (app()->environment('testing')) {
            return 0.0;
        }

        return 1.05;
    }

    private static function sleepPauseAware(float $seconds): void
    {
        if ($seconds <= 0 || app()->environment('testing')) {
            self::guardPause();

            return;
        }

        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            self::guardPause();
            $left = $end - microtime(true);
            if ($left <= 0) {
                break;
            }
            usleep((int) (min(0.8, $left) * 1_000_000));
        }

        self::guardPause();
    }
}
