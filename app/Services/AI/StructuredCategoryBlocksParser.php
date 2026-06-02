<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Parses menu descriptions of the form:
 *
 *   Category Label : {
 *   meal or ingredient name : price
 *   }
 *
 * Blocks may be empty (category-only). Supports UTF-8 category and item names.
 */
final class StructuredCategoryBlocksParser
{
    /**
     * @return list<array{label: string, body: string}>
     */
    public static function parse(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $blocks = [];
        $offset = 0;
        $len = strlen($text);

        while ($offset < $len) {
            while ($offset < $len && ctype_space($text[$offset])) {
                $offset++;
            }
            if ($offset >= $len) {
                break;
            }

            if (!preg_match('/(.+?)\s*:\s*\{/s', $text, $m, 0, $offset)) {
                break;
            }

            $label = trim($m[1]);
            $offset += strlen($m[0]);
            $depth = 1;
            $startBody = $offset;

            while ($offset < $len && $depth > 0) {
                $ch = $text[$offset];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                }
                $offset++;
            }

            if ($depth !== 0) {
                break;
            }

            $body = substr($text, $startBody, $offset - $startBody - 1);
            $blocks[] = ['label' => $label, 'body' => $body];
        }

        while ($offset < $len && ctype_space($text[$offset])) {
            $offset++;
        }

        return $blocks;
    }

    /**
     * Like parse(), but ensures the entire string is consumed and at least one block exists.
     *
     * @return array{ok: true, blocks: list<array{label: string, body: string}>}|array{ok: false, error: string}
     */
    public static function parseStrict(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Description is required.'];
        }

        $blocks = [];
        $offset = 0;
        $len = strlen($text);

        while ($offset < $len) {
            while ($offset < $len && ctype_space($text[$offset])) {
                $offset++;
            }
            if ($offset >= $len) {
                break;
            }

            if (!preg_match('/(.+?)\s*:\s*\{/s', $text, $m, 0, $offset)) {
                return [
                    'ok' => false,
                    'error' => 'Expected blocks like: Category Name : { item name : price }',
                ];
            }

            $label = trim($m[1]);
            if ($label === '') {
                return ['ok' => false, 'error' => 'Each block needs a category name before ":".'];
            }

            $offset += strlen($m[0]);
            $depth = 1;
            $startBody = $offset;

            while ($offset < $len && $depth > 0) {
                $ch = $text[$offset];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                }
                $offset++;
            }

            if ($depth !== 0) {
                return ['ok' => false, 'error' => 'Unclosed "{" in description. Check that every block ends with "}".'];
            }

            $body = substr($text, $startBody, $offset - $startBody - 1);
            $blocks[] = ['label' => $label, 'body' => $body];
        }

        while ($offset < $len && ctype_space($text[$offset])) {
            $offset++;
        }

        if ($offset < $len) {
            return [
                'ok' => false,
                'error' => 'Extra text after the last block. Remove text after the final "}".',
            ];
        }

        if ($blocks === []) {
            return ['ok' => false, 'error' => 'No category blocks found.'];
        }

        return ['ok' => true, 'blocks' => $blocks];
    }
}
