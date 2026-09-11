<?php

declare(strict_types=1);

namespace App\Support\AppDevelopment;

final class TicketMedia
{
    /** @var list<string> */
    public const EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp',
        'mp4', 'mov', 'webm',
        'ogg', 'mp3', 'm4a', 'wav', 'aac',
        'pdf', 'txt', 'log', 'zip',
    ];

    /** 200 MB — large enough for task videos without PHP timeout when chunked. */
    public const MAX_KILOBYTES = 204800;

    public const CHUNK_KILOBYTES = 1024;

    public static function extensionsRule(): string
    {
        return 'extensions:'.implode(',', self::EXTENSIONS);
    }

    public static function acceptAttribute(): string
    {
        return implode(',', array_map(
            static fn (string $ext): string => '.'.$ext,
            self::EXTENSIONS,
        )).',image/*,video/*,audio/*';
    }

    /**
     * @return list<string|\Illuminate\Validation\Rules\File>
     */
    public static function fileRules(bool $required = false): array
    {
        $rules = [
            'file',
            'max:'.self::MAX_KILOBYTES,
            self::extensionsRule(),
        ];

        array_unshift($rules, $required ? 'required' : 'nullable');

        return $rules;
    }
}
