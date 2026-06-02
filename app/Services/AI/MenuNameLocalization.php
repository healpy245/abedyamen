<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Applies translate-on or mirror-input behavior for name_ar / name_en / name_he fields.
 */
final class MenuNameLocalization
{
    private const int BATCH_SIZE = 40;

    public static function translateNamesEnabled(array $payload): bool
    {
        if (!array_key_exists('translate_names', $payload)) {
            return true;
        }

        return filter_var($payload['translate_names'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @param  callable(string, string, array<string, mixed>): string  $chat
     * @param  callable(string, string, array<string, mixed>): void|null  $progress
     * @return array<string, array<string, mixed>>
     */
    public static function apply(
        array $records,
        bool $translateNames,
        callable $chat,
        ?callable $progress = null,
    ): array {
        if ($records === []) {
            return $records;
        }

        if (!$translateNames) {
            return self::mirrorNames($records);
        }

        $toTranslate = [];
        foreach ($records as $record) {
            if (!self::needsTranslation($record)) {
                continue;
            }
            $source = self::sourceName($record);
            if ($source !== '') {
                $toTranslate[$source] = true;
            }
        }

        if ($toTranslate === []) {
            return $records;
        }

        $progress && $progress('translate', 'Translating ' . count($toTranslate) . ' names to Arabic, English, and Hebrew...', [
            'count' => count($toTranslate),
        ]);

        $map = self::translateBatch($chat, array_keys($toTranslate));

        foreach ($records as $key => $record) {
            $source = self::sourceName($record);
            if ($source === '' || !isset($map[$source])) {
                continue;
            }
            $records[$key]['name_ar'] = $map[$source]['name_ar'];
            $records[$key]['name_en'] = $map[$source]['name_en'];
            $records[$key]['name_he'] = $map[$source]['name_he'];
        }

        $progress && $progress('translate', 'Name translation complete', ['count' => count($map)]);

        return $records;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function sourceName(array $record): string
    {
        foreach (['name_en', 'name_ar', 'name_he', 'name'] as $field) {
            $value = trim((string) ($record[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function needsTranslation(array $record): bool
    {
        $ar = trim((string) ($record['name_ar'] ?? ''));
        $en = trim((string) ($record['name_en'] ?? ''));
        $he = trim((string) ($record['name_he'] ?? ''));

        if ($ar === '' && $en === '' && $he === '') {
            return false;
        }

        return $ar === $en && $en === $he;
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    public static function mirrorNames(array $records): array
    {
        foreach ($records as $key => $record) {
            $source = self::sourceName($record);
            $records[$key]['name_ar'] = $source;
            $records[$key]['name_en'] = $source;
            $records[$key]['name_he'] = $source;
        }

        return $records;
    }

    /**
     * @param  callable(string, string, array<string, mixed>): string  $chat
     * @param  list<string>  $names
     * @return array<string, array{name_ar: string, name_en: string, name_he: string}>
     */
    public static function translateBatch(callable $chat, array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names))));
        $result = [];

        foreach (array_chunk($names, self::BATCH_SIZE) as $chunk) {
            $chunkMap = self::translateChunk($chat, $chunk);
            foreach ($chunkMap as $source => $triplet) {
                $result[$source] = $triplet;
            }
        }

        foreach ($names as $name) {
            if (!isset($result[$name])) {
                $result[$name] = [
                    'name_ar' => $name,
                    'name_en' => $name,
                    'name_he' => $name,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  callable(string, string, array<string, mixed>): string  $chat
     * @param  list<string>  $names
     * @return array<string, array{name_ar: string, name_en: string, name_he: string}>
     */
    private static function translateChunk(callable $chat, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $listJson = json_encode($names, JSON_UNESCAPED_UNICODE);
        $systemPrompt = <<<'PROMPT'
You translate restaurant menu names into Arabic, English, and Hebrew.

Return ONLY valid JSON (no markdown):
{
  "translations": {
    "exact input name": {
      "name_ar": "...",
      "name_en": "...",
      "name_he": "..."
    }
  }
}

Rules:
- Keys in "translations" MUST match the input strings exactly (same spelling and spacing).
- name_ar: Arabic WITHOUT tashkeel/diacritics.
- name_en: natural English (translate if input is Arabic/Hebrew).
- name_he: natural Hebrew.
- Keep brand names and well-known loanwords sensible in each language.
PROMPT;

        $userPrompt = "Translate these menu names:\n" . $listJson;

        try {
            $response = $chat($systemPrompt, $userPrompt, ['max_tokens' => 4096, 'temperature' => 0.2]);
        } catch (\Throwable) {
            return self::fallbackMap($names);
        }

        return self::parseTranslationResponse($response, $names);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array{name_ar: string, name_en: string, name_he: string}>
     */
    private static function parseTranslationResponse(string $response, array $names): array
    {
        $response = trim($response);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
            $response = trim($m[1]);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            if (preg_match('/\{[\s\S]*"translations"[\s\S]*\}/', $response, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        $map = [];
        $translations = is_array($decoded['translations'] ?? null) ? $decoded['translations'] : [];

        foreach ($names as $name) {
            $entry = $translations[$name] ?? null;
            if (!is_array($entry)) {
                $map[$name] = ['name_ar' => $name, 'name_en' => $name, 'name_he' => $name];
                continue;
            }
            $map[$name] = [
                'name_ar' => trim((string) ($entry['name_ar'] ?? $name)),
                'name_en' => trim((string) ($entry['name_en'] ?? $name)),
                'name_he' => trim((string) ($entry['name_he'] ?? $name)),
            ];
        }

        return $map;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array{name_ar: string, name_en: string, name_he: string}>
     */
    private static function fallbackMap(array $names): array
    {
        $map = [];
        foreach ($names as $name) {
            $map[$name] = ['name_ar' => $name, 'name_en' => $name, 'name_he' => $name];
        }

        return $map;
    }

    /**
     * @param  callable(string, string, array<string, mixed>): string  $chat
     * @return array{name_ar: string, name_en: string, name_he: string}
     */
    public static function namesForLabel(string $label, bool $translateNames, ?callable $chat = null): array
    {
        $label = trim($label);
        if ($label === '') {
            return ['name_ar' => '', 'name_en' => '', 'name_he' => ''];
        }

        if (!$translateNames || $chat === null) {
            return ['name_ar' => $label, 'name_en' => $label, 'name_he' => $label];
        }

        $map = self::translateBatch($chat, [$label]);

        return $map[$label] ?? ['name_ar' => $label, 'name_en' => $label, 'name_he' => $label];
    }
}
