<?php

declare(strict_types=1);

namespace App\Services\Form;

use App\Support\KamanUrl;

/**
 * Finds "{subdomain}.kaman.rest, email, password" in a chat message and removes the
 * password from the text before anything stores it or sends it to the model.
 */
final class KamanChatCredentials
{
    public const HIDDEN = '[hidden]';

    private const LABEL = '(?<![\p{L}\p{N}])(?:password|passcode|passwd|pass|pwd|باسورد|الباسورد|كلمة\s*(?:السر|المرور)|סיסמה|סיסמא)(?![\p{L}\p{N}])';

    /**
     * @return array{base_url: string, subdomain: string, tld: string, email: string, password: string, text: string}|null
     */
    public function parse(string $text): ?array
    {
        $host = $this->host($text);
        if ($host === null) {
            return null;
        }
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text, $emailMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $email = $emailMatch[0][0];
        $afterEmail = (int) $emailMatch[0][1] + strlen($email);

        $found = null;
        if (preg_match('/'.self::LABEL.'\s*(?:is\b|هي|=|:|-)?\s*(\S+)/iu', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $found = $match[1];
        } elseif (preg_match('/\G[\s,;:|\/]+(?:(?:and|&|و)\s+)?(\S+)/iu', $text, $match, PREG_OFFSET_CAPTURE, $afterEmail) === 1) {
            $found = $match[1];
        }
        if ($found === null) {
            return null;
        }

        $raw = (string) $found[0];
        $password = trim(rtrim($raw, ',;'), '"\'“”«»');
        if ($password === '' || $password === self::HIDDEN) {
            return null;
        }
        $offset = (int) $found[1] + (int) strpos($raw, $password);

        return [
            'base_url' => KamanUrl::managerApi($host['subdomain'], $host['tld']),
            'subdomain' => $host['subdomain'],
            'tld' => $host['tld'],
            'email' => $email,
            'password' => $password,
            'text' => $this->hide(substr_replace($text, self::HIDDEN, $offset, strlen($password)), $password),
        ];
    }

    /**
     * Removes the password from every user message in the conversation.
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return array{history: list<array{role: string, content: string}>, credentials: ?array, in_latest: bool}
     */
    public function scrubHistory(array $history): array
    {
        $found = [];
        $latestUser = null;
        foreach ($history as $index => $row) {
            if (($row['role'] ?? '') !== 'user') {
                continue;
            }
            $latestUser = $index;
            $parsed = $this->parse((string) ($row['content'] ?? ''));
            if ($parsed !== null) {
                $found[$index] = $parsed;
                $history[$index]['content'] = $parsed['text'];
            }
        }
        foreach ($found as $parsed) {
            foreach ($history as $index => $row) {
                $history[$index]['content'] = $this->hide((string) ($row['content'] ?? ''), $parsed['password']);
            }
        }

        $last = $found === [] ? null : $found[array_key_last($found)];

        return [
            'history' => $history,
            'credentials' => $last,
            'in_latest' => $latestUser !== null && isset($found[$latestUser]),
        ];
    }

    public function hide(string $text, string $password): string
    {
        // A very short password could also be part of a price or an address.
        return strlen($password) >= 8 ? str_replace($password, self::HIDDEN, $text) : $text;
    }

    /**
     * The restaurant to log in to. Prefers a host followed by /login, and never a public
     * /menu link, which is a source and not a target.
     *
     * @return array{subdomain: string, tld: string}|null
     */
    private function host(string $text): ?array
    {
        if (preg_match_all('#(?<![@\w.\-])(?:https?://)?([a-z0-9\-]+)\.kaman\.(rest|dev)\b(/[^\s,]*)?#iu', $text, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }
        $choice = null;
        foreach ($matches as $match) {
            $path = strtolower($match[3] ?? '');
            if (str_starts_with($path, '/login')) {
                $choice = $match;
                break;
            }
            if (! str_starts_with($path, '/menu')) {
                $choice ??= $match;
            }
        }
        if ($choice === null) {
            return null;
        }

        return ['subdomain' => KamanUrl::normalizeSubdomain($choice[1]), 'tld' => strtolower($choice[2])];
    }
}
