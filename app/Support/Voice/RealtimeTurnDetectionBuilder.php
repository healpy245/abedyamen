<?php

declare(strict_types=1);

namespace App\Support\Voice;

final class RealtimeTurnDetectionBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $type = strtolower((string) config('voice.realtime.vad.type', 'server_vad'));
        $allowedTypes = ['server_vad', 'semantic_vad'];

        if (! in_array($type, $allowedTypes, true)) {
            $type = 'server_vad';
        }

        $config = [
            'type' => $type,
            'create_response' => filter_var(config('voice.realtime.vad.create_response', true), FILTER_VALIDATE_BOOLEAN),
            'interrupt_response' => filter_var(config('voice.realtime.vad.interrupt_response', true), FILTER_VALIDATE_BOOLEAN),
        ];

        if ($type === 'semantic_vad') {
            $eagerness = (string) config('voice.realtime.vad.eagerness', 'auto');
            if (in_array($eagerness, ['low', 'medium', 'high', 'auto'], true)) {
                $config['eagerness'] = $eagerness;
            }

            return $config;
        }

        $config['threshold'] = (float) config('voice.realtime.vad.threshold', 0.5);
        $config['prefix_padding_ms'] = (int) config('voice.realtime.vad.prefix_padding_ms', 400);
        $config['silence_duration_ms'] = (int) config('voice.realtime.vad.silence_duration_ms', 750);

        return $config;
    }
}
