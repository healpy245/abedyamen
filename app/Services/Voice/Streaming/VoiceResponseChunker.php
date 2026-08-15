<?php

declare(strict_types=1);

namespace App\Services\Voice\Streaming;

/**
 * Split assistant text into speakable phrases for low time-to-first-audio.
 */
class VoiceResponseChunker
{
    public function __construct(
        protected int $firstMax = 70,
        protected int $nextMax = 140,
        protected int $minFirst = 18,
    ) {}

    /**
     * @return list<string>
     */
    public function split(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $this->firstMax) {
            return [$text];
        }

        $parts = preg_split('/(?<=[.!?؟…،；;:])\s+/u', $text) ?: [$text];
        $parts = array_values(array_filter(array_map('trim', $parts)));

        $chunks = [];
        $buf = '';
        foreach ($parts as $i => $part) {
            $limit = $chunks === [] ? $this->firstMax : $this->nextMax;
            $candidate = $buf === '' ? $part : $buf.' '.$part;

            if ($buf !== '' && mb_strlen($candidate) > $limit) {
                if ($chunks === [] && mb_strlen($buf) < $this->minFirst && $part !== '') {
                    $buf = $candidate;
                    continue;
                }
                $chunks[] = $buf;
                $buf = $part;
                continue;
            }

            $buf = $candidate;
        }

        if ($buf !== '') {
            $chunks[] = $buf;
        }

        return $this->protectSensitiveSplits($chunks);
    }

    /**
     * Flush buffer for streaming: return phrase if ready, else null.
     *
     * @return array{phrase:?string,buffer:string}
     */
    public function feed(string $buffer, string $delta, bool $isFirstPhrase): array
    {
        $buffer .= $delta;
        $limit = $isFirstPhrase ? $this->firstMax : $this->nextMax;
        $min = $isFirstPhrase ? $this->minFirst : 28;

        if (preg_match('/^(.*?[.!?؟…])(\s+|$)/u', $buffer, $m)) {
            $phrase = trim($m[1]);
            $rest = trim(substr($buffer, strlen($m[0])));
            if ($phrase !== '' && (! $isFirstPhrase || mb_strlen($phrase) >= $min || str_ends_with($phrase, '؟') || str_ends_with($phrase, '?'))) {
                return ['phrase' => $phrase, 'buffer' => $rest];
            }
        }

        // Soft flush on Arabic comma once long enough for first chunk.
        if ($isFirstPhrase && mb_strlen($buffer) >= $min && preg_match('/^(.*?،)\s+/u', $buffer, $m)) {
            $phrase = trim($m[1]);
            $rest = trim(substr($buffer, strlen($m[0])));
            if (mb_strlen($phrase) >= $min) {
                return ['phrase' => $phrase, 'buffer' => $rest];
            }
        }

        if (mb_strlen($buffer) >= $limit) {
            $cut = $this->safeCut($buffer, $limit);
            $phrase = trim(mb_substr($buffer, 0, $cut));
            $rest = trim(mb_substr($buffer, $cut));

            return ['phrase' => $phrase !== '' ? $phrase : null, 'buffer' => $rest];
        }

        return ['phrase' => null, 'buffer' => $buffer];
    }

    private function safeCut(string $text, int $limit): int
    {
        $slice = mb_substr($text, 0, $limit);
        // Avoid splitting digit runs (phones/amounts).
        if (preg_match('/\d{3,}$/u', $slice)) {
            $pos = mb_strlen($slice);
            while ($pos > 10 && preg_match('/\d/u', mb_substr($text, $pos - 1, 1))) {
                $pos--;
            }

            return max(10, $pos);
        }

        $space = mb_strrpos($slice, ' ');
        if ($space !== false && $space >= (int) ($limit * 0.45)) {
            return $space;
        }

        return $limit;
    }

    /**
     * @param  list<string>  $chunks
     * @return list<string>
     */
    private function protectSensitiveSplits(array $chunks): array
    {
        // Merge a chunk that is only leftover digits into previous.
        $out = [];
        foreach ($chunks as $chunk) {
            if ($out !== [] && preg_match('/^\d{2,}$/u', $chunk)) {
                $out[count($out) - 1] .= ' '.$chunk;
                continue;
            }
            $out[] = $chunk;
        }

        return $out;
    }
}
