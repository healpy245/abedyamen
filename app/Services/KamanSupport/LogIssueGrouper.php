<?php

declare(strict_types=1);

namespace App\Services\KamanSupport;

/**
 * Groups already-loaded support logs into investigation issues.
 * Grouping is presentation only: every original record stays on the issue.
 */
class LogIssueGrouper
{
    /**
     * @param  list<array<string, mixed>>  $rows  Newest first is preferred; order is normalized here.
     * @return list<array<string, mixed>>
     */
    public function group(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)));

        $buckets = [];
        $order = [];
        $signals = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $signals[$id] = $this->interpret($row);
            $fingerprint = $this->fingerprint($row, $signals[$id]);
            if (! isset($buckets[$fingerprint])) {
                $buckets[$fingerprint] = [];
                $order[] = $fingerprint;
            }
            $buckets[$fingerprint][] = $row;
        }

        $issues = [];
        foreach ($order as $fingerprint) {
            $issues[] = $this->present($fingerprint, $buckets[$fingerprint], $signals);
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array{severity?: string, category?: string, integration?: string, device?: string, http?: string}  $filters
     */
    public function accepts(array $issue, array $filters): bool
    {
        $severity = (string) ($filters['severity'] ?? '');
        $category = (string) ($filters['category'] ?? '');
        $integration = (string) ($filters['integration'] ?? '');
        $device = mb_strtolower(trim((string) ($filters['device'] ?? '')));
        $http = trim((string) ($filters['http'] ?? ''));
        $level = (string) ($issue['level'] ?? '');

        if ($severity !== '' && $level !== $severity && ! ($severity === 'warn' && $level === 'warning')) {
            return false;
        }
        if ($category === 'network' && empty($issue['network'])) {
            return false;
        }
        if ($category !== '' && $category !== 'network' && (string) ($issue['logKind'] ?? '') !== $category) {
            return false;
        }
        if ($integration !== '' && (string) ($issue['service'] ?? '') !== $integration) {
            return false;
        }
        if ($http !== '' && (string) ($issue['code'] ?? '') !== $http) {
            return false;
        }
        if ($device !== '') {
            $names = is_array($issue['devices'] ?? null) ? $issue['devices'] : [];
            $haystack = mb_strtolower(trim(implode(' ', array_merge([(string) ($issue['device'] ?? '')], $names))));
            if ($haystack === '' || ! str_contains($haystack, $device)) {
                return false;
            }
        }

        return true;
    }

    public function looksTechnicalId(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        return preg_match('/^[0-9a-f]{16,}$/i', $value) === 1;
    }

    public function isPlaceholder(string $value): bool
    {
        $value = mb_strtolower(trim($value, " \t\n\r\0\x0B_-"));

        return $value === '' || in_array($value, ['pending', 'unknown', 'null', 'none', 'na', 'n/a'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $signals
     */
    private function fingerprint(array $row, array $signals): string
    {
        $host = (string) ($signals['host'] ?? '');
        if ($host !== '') {
            $parts = [
                (string) ($row['level'] ?? ''),
                (string) ($row['logKind'] ?? ''),
                (string) ($row['service'] ?? ''),
                $this->restaurantKey($row),
                'host:'.$host,
            ];
        } else {
            $parts = [
                (string) ($row['level'] ?? ''),
                (string) ($signals['type'] ?? ''),
                (string) ($row['logKind'] ?? ''),
                (string) ($row['service'] ?? ''),
                $this->restaurantKey($row),
                (string) ($signals['method'] ?? ''),
                (string) ($signals['endpointPattern'] ?? ''),
                (string) ($signals['code'] ?? ''),
                (string) ($signals['normalized'] ?? ''),
            ];
        }

        return hash('sha1', implode("\x1e", $parts));
    }

    /**
     * @param  list<array<string, mixed>>  $bucket
     * @param  array<string, array<string, string|null>>  $signals
     * @return array<string, mixed>
     */
    private function present(string $fingerprint, array $bucket, array $signals): array
    {
        $latest = $bucket[0];
        $latestId = (string) ($latest['id'] ?? '');
        $latestSignals = $signals[$latestId] ?? $this->interpret($latest);
        $oldest = $bucket[array_key_last($bucket)];
        $devices = [];
        $network = false;
        $occurrences = [];

        foreach ($bucket as $row) {
            $rowSignals = $signals[(string) ($row['id'] ?? '')] ?? $this->interpret($row);
            $name = trim((string) ($row['device'] ?? ''));
            if ($name !== '' && ! in_array($name, $devices, true)) {
                $devices[] = $name;
            }
            $network = $network || $this->isNetwork($row);
            $split = $this->splitContext(is_array($row['context'] ?? null) ? $row['context'] : [], (string) ($row['message'] ?? ''));
            $occurrences[] = [
                'id' => (int) ($row['id'] ?? 0),
                'at' => (string) ($row['at'] ?? ''),
                'clock' => (string) ($row['clock'] ?? ''),
                'device' => $name,
                'deviceId' => (string) ($row['deviceId'] ?? ''),
                'requestId' => $this->requestId(is_array($row['context'] ?? null) ? $row['context'] : [], (string) ($row['message'] ?? '')),
                'message' => (string) ($row['message'] ?? ''),
                'method' => (string) ($rowSignals['method'] ?? ''),
                'endpoint' => $this->fullEndpoint(is_array($row['context'] ?? null) ? $row['context'] : [], (string) ($row['message'] ?? ''), $rowSignals),
                'requestBody' => $this->stringify($this->requestBodyValue(is_array($row['context'] ?? null) ? $row['context'] : [])),
                'code' => (string) ($rowSignals['code'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'sourceLabel' => (string) ($row['sourceLabel'] ?? ''),
                'platform' => (string) ($row['platform'] ?? ''),
                'version' => (string) ($row['version'] ?? ''),
                'restaurant' => (string) ($row['restaurant'] ?? ''),
                'level' => (string) ($row['level'] ?? ''),
                'stack' => $split['stack'],
                'exchange' => $split['exchange'],
                'meta' => $split['meta'],
                'raw' => $this->raw($row, $rowSignals),
            ];
        }

        $title = (string) ($latestSignals['title'] ?? '');
        $level = (string) ($latest['level'] ?? '');

        return [
            'key' => substr($fingerprint, 0, 12),
            'title' => $title !== '' ? $title : 'Log',
            'level' => $level,
            'tone' => $this->tone($latest, (string) ($latestSignals['code'] ?? '')),
            'restaurant' => (string) ($latest['restaurant'] ?? ''),
            'restaurantId' => (string) ($latest['restaurantId'] ?? ''),
            'device' => $devices[0] ?? '',
            'deviceExtra' => max(0, count($devices) - 1),
            'devices' => $devices,
            'sourceLabel' => (string) ($latest['sourceLabel'] ?? ''),
            'service' => (string) ($latest['service'] ?? ''),
            'logKind' => (string) ($latest['logKind'] ?? ''),
            'network' => $network,
            'count' => count($bucket),
            'firstAt' => (string) ($oldest['at'] ?? ''),
            'lastAt' => (string) ($latest['at'] ?? ''),
            'lastClock' => (string) ($latest['clock'] ?? ''),
            'code' => (string) ($latestSignals['code'] ?? ''),
            'method' => (string) ($latestSignals['method'] ?? ''),
            'endpoint' => (string) ($latestSignals['endpoint'] ?? ''),
            'explanation' => $latestSignals['explanation'] ?? null,
            'message' => (string) ($latest['message'] ?? ''),
            'platform' => (string) ($latest['platform'] ?? ''),
            'version' => (string) ($latest['version'] ?? ''),
            'occurrences' => $occurrences,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{method: string, endpoint: string, endpointPattern: string, code: string, type: string, host: string, normalized: string, title: string, explanation: ?string}
     */
    private function interpret(array $row): array
    {
        $message = (string) ($row['message'] ?? '');
        $context = is_array($row['context'] ?? null) ? $row['context'] : [];
        $method = strtoupper(trim((string) ($row['method'] ?? '')));
        $endpoint = trim((string) ($row['endpoint'] ?? ''));
        $code = trim((string) ($row['code'] ?? ''));

        if ($method === '' && isset($context['method']) && is_scalar($context['method'])) {
            $method = strtoupper(trim((string) $context['method']));
        }
        if ($endpoint === '' && isset($context['path']) && is_scalar($context['path'])) {
            $endpoint = trim((string) $context['path']);
        }
        if ($code === '' && isset($context['status']) && is_scalar($context['status'])) {
            $code = trim((string) $context['status']);
        }
        if ($method === '' && preg_match('/\b(GET|POST|PUT|PATCH|DELETE|HEAD)\b/', $message, $match) === 1) {
            $method = strtoupper($match[1]);
        }
        if ($endpoint === '') {
            if (preg_match('#https?://[^\s\'"]+#i', $message, $match) === 1) {
                $path = parse_url($match[0], PHP_URL_PATH);
                if (is_string($path) && $path !== '') {
                    $endpoint = $path;
                }
            } elseif (preg_match('#(/api/[^\s\'"?]+)#', $message, $match) === 1) {
                $endpoint = $match[1];
            }
        }
        if ($code === '' && preg_match('/->\s*(\d{3})\b/', $message, $match) === 1) {
            $code = $match[1];
        }

        $endpoint = strtok($endpoint, '?') ?: $endpoint;
        $tail = $message;
        if (preg_match('/->\s*\d{3}\b\s*(.*)$/su', $message, $match) === 1) {
            $tail = (string) $match[1];
        }
        $tail = $this->cleanTail($tail);
        $type = '';
        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*(?:Exception|Error))/', $message, $match) === 1) {
            $type = $match[1];
        }
        $host = '';
        if (preg_match("/Failed host lookup:\\s*'([^']+)'/i", $message, $match) === 1) {
            $host = mb_strtolower($match[1]);
        }

        $title = $this->titleFrom($row, $message, $tail, $type, $method, $endpoint, $code);
        $normalized = $this->normalizeText($tail !== '' ? $tail : $title);

        return [
            'method' => $method,
            'endpoint' => $endpoint,
            'endpointPattern' => $this->endpointPattern($endpoint),
            'code' => $code,
            'type' => $type,
            'host' => $host,
            'normalized' => $normalized,
            'title' => $title,
            'explanation' => $this->humanExplanation($title),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function titleFrom(array $row, string $message, string $tail, string $type, string $method, string $endpoint, string $code): string
    {
        $source = $tail !== '' ? $tail : $message;
        if (preg_match("/Failed host lookup:\\s*'([^']+)'/i", $source, $match) === 1) {
            return $this->clip('Failed host lookup: '.$match[1]);
        }

        $candidate = $tail;
        if ($this->looksRaw($candidate)) {
            $summary = trim((string) ($row['summary'] ?? ''));
            $candidate = $this->looksRaw($summary) ? '' : $summary;
        }
        if ($candidate === '' && $type !== '') {
            $snippet = '';
            if (preg_match('/'.preg_quote($type, '/').':\s*([^\n(]{3,80})/', $message, $match) === 1) {
                $snippet = trim($match[1]);
            }
            $candidate = $this->looksRaw($snippet) || $snippet === '' ? $type : $snippet;
        }
        if ($candidate === '') {
            $path = $this->endpointPattern($endpoint);
            if (mb_strlen($path) > 42) {
                $path = '…'.mb_substr($path, -36);
            }
            $candidate = trim($method.' '.$path);
            if ($code !== '' && $candidate !== '') {
                $candidate .= ' → '.$code;
            } elseif ($code !== '') {
                $candidate = 'HTTP '.$code;
            }
        }

        $candidate = trim(preg_replace('/\s+/u', ' ', $candidate) ?? $candidate);

        return $this->clip($candidate !== '' ? $candidate : 'Log');
    }

    private function humanExplanation(string $title): ?string
    {
        if ($title === '' || $title === 'Log' || str_contains($title, '→')) {
            return null;
        }
        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD)\b/', $title) === 1) {
            return null;
        }

        return $title;
    }

    private function cleanTail(string $tail): string
    {
        $tail = preg_replace('/\bbody\s*=\s*\{.*$/su', '', $tail) ?? $tail;
        $tail = preg_replace('/\{.*$/su', '', $tail) ?? $tail;
        $tail = preg_replace('#https?://\S+#i', '', $tail) ?? $tail;
        $tail = preg_replace('/^(?:api\s+)?(?:GET|POST|PUT|PATCH|DELETE|HEAD)\b\s*/i', '', $tail) ?? $tail;
        $tail = preg_replace('/\b(?:req|request|trace|span|correlation)[_-]?id\b\s*[:=]\s*\S+/i', '', $tail) ?? $tail;
        $tail = preg_replace('/\b\d{4}-\d{2}-\d{2}[t\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:z|[+\-]\d{2}:?\d{2})?\b/i', '', $tail) ?? $tail;
        $tail = preg_replace('/\b\d+b\b/i', '', $tail) ?? $tail;
        $tail = preg_replace('/\b\d+ms\b/i', '', $tail) ?? $tail;
        $tail = preg_replace('/\(OS Error:.*?\)/iu', '', $tail) ?? $tail;
        $tail = preg_replace('/\bat\b\s*$/i', '', $tail) ?? $tail;
        $tail = trim(preg_replace('/\s+/u', ' ', $tail) ?? $tail);

        return $tail;
    }

    private function looksRaw(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }
        if (str_contains($text, '{') || str_contains($text, 'body=')) {
            return true;
        }
        if (preg_match('#https?://#i', $text) === 1) {
            return true;
        }

        return preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD|API)\b/i', $text) === 1 && str_contains($text, '/');
    }

    private function normalizeText(string $text): string
    {
        $text = $this->stripVolatile($text);
        $text = mb_strtolower($text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return rtrim($text, '.');
    }

    private function stripVolatile(string $text): string
    {
        $text = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', ':id', $text) ?? $text;
        $text = preg_replace('/\b[0-9a-f]{16,}\b/i', ':id', $text) ?? $text;
        $text = preg_replace('/\b\d{4}-\d{2}-\d{2}[t\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:z|[+\-]\d{2}:?\d{2})?\b/i', ':ts', $text) ?? $text;
        $text = preg_replace('/\b\d{2}:\d{2}:\d{2}\b/', ':ts', $text) ?? $text;
        $text = preg_replace('/\b(?:req|request|trace|span|correlation)[_-]?id\b\s*[:=]\s*\S+/i', 'request_id=:id', $text) ?? $text;
        $text = preg_replace('/\b\d{6,}\b/', ':n', $text) ?? $text;

        return $text;
    }

    private function endpointPattern(string $endpoint): string
    {
        $path = strtok($endpoint, '?') ?: $endpoint;
        $path = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', ':id', $path) ?? $path;
        $path = preg_replace('#/\d+(?=/|$)#', '/:id', $path) ?? $path;

        return mb_strtolower($path);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function restaurantKey(array $row): string
    {
        $id = trim((string) ($row['restaurantId'] ?? ''));
        if ($id !== '') {
            return mb_strtolower($id);
        }

        return mb_strtolower(trim((string) ($row['restaurant'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isNetwork(array $row): bool
    {
        if (! empty($row['network'])) {
            return true;
        }

        return preg_match('/network|sync|connection|host lookup|socket/i', (string) ($row['message'] ?? '')) === 1;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function tone(array $row, string $code): string
    {
        $level = (string) ($row['level'] ?? '');
        if ($level === 'error') {
            return 'error';
        }
        if ($level === 'warn' || $level === 'warning') {
            return 'warn';
        }
        if ($code !== '' && str_starts_with($code, '2')) {
            return 'ok';
        }

        return 'info';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{stack: ?string, exchange: ?string, meta: ?string}
     */
    private function splitContext(array $context, string $message): array
    {
        $stack = null;
        foreach (['stack', 'stacktrace', 'stack_trace', 'trace', 'exception'] as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }
            $stack = $this->stringify($context[$key]);
            unset($context[$key]);
            if ($stack !== null && $stack !== '') {
                break;
            }
        }
        if ($stack === null || $stack === '') {
            $stack = $this->stackFromMessage($message);
        }

        $exchange = [];
        foreach (['request', 'response', 'req', 'res', 'body', 'payload', 'request_body', 'response_body'] as $key) {
            if (array_key_exists($key, $context)) {
                $exchange[$key] = $context[$key];
                unset($context[$key]);
            }
        }
        foreach (['request_id', 'requestId', 'trace_id', 'traceId', 'correlation_id', 'correlationId', 'x-request-id', 'x_request_id'] as $key) {
            unset($context[$key]);
        }

        return [
            'stack' => $stack !== '' ? $stack : null,
            'exchange' => $exchange === [] ? null : $this->stringify($exchange),
            'meta' => $context === [] ? null : $this->stringify($context),
        ];
    }

    private function stackFromMessage(string $message): ?string
    {
        if (preg_match('/(?:^|\R)((?:#\d+\s+\S.*(?:\R|$))+)/', $message, $match) === 1) {
            return trim($match[1]);
        }
        if (preg_match('/(?:^|\R)((?:at\s+\S.*(?:\R|$))+)/', $message, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function requestId(array $context, string $message): string
    {
        foreach (['request_id', 'requestId', 'trace_id', 'traceId', 'correlation_id', 'correlationId', 'x-request-id', 'x_request_id'] as $key) {
            if (! isset($context[$key]) || ! is_scalar($context[$key])) {
                continue;
            }
            $value = trim((string) $context[$key]);
            if ($value !== '' && $value !== '—') {
                return $value;
            }
        }
        if (preg_match('/request[_ ]?id["\s:=]+([A-Za-z0-9\-.]{6,})/i', $message, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $signals
     */
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $signals
     */
    private function fullEndpoint(array $context, string $message, array $signals): string
    {
        foreach (['url', 'uri', 'endpoint', 'request_url', 'requestUrl', 'full_url', 'fullUrl'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key]) && trim((string) $context[$key]) !== '') {
                return trim((string) $context[$key]);
            }
        }

        $request = $context['request'] ?? $context['req'] ?? null;
        if (is_array($request)) {
            foreach (['url', 'uri', 'endpoint', 'path'] as $key) {
                if (isset($request[$key]) && is_scalar($request[$key]) && trim((string) $request[$key]) !== '') {
                    return trim((string) $request[$key]);
                }
            }
        }

        if (preg_match('#https?://[^\s\'"]+#i', $message, $match) === 1) {
            return $match[0];
        }

        $path = '';
        if (isset($context['path']) && is_scalar($context['path'])) {
            $path = trim((string) $context['path']);
        }
        if ($path === '') {
            $path = trim((string) ($signals['endpoint'] ?? ''));
        }
        $query = '';
        foreach (['query', 'search'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key]) && trim((string) $context[$key]) !== '') {
                $query = trim((string) $context[$key]);
                break;
            }
        }
        if ($query !== '' && ! str_contains($path, '?')) {
            $path .= (str_starts_with($query, '?') ? '' : '?').$query;
        }

        $method = trim((string) ($signals['method'] ?? ''));
        if ($method !== '' && $path !== '' && ! str_starts_with(strtoupper($path), $method.' ')) {
            return $method.' '.$path;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function requestBodyValue(array $context): mixed
    {
        foreach (['body', 'payload', 'request_body', 'requestBody', 'json', 'params'] as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            return $value;
        }

        $request = $context['request'] ?? $context['req'] ?? null;
        if (! is_array($request)) {
            return null;
        }
        foreach (['body', 'payload', 'data', 'json', 'params'] as $key) {
            if (! array_key_exists($key, $request)) {
                continue;
            }
            $value = $request[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $signals
     */
    private function raw(array $row, array $signals): string
    {
        $context = is_array($row['context'] ?? null) ? $row['context'] : [];
        $endpoint = $this->fullEndpoint($context, (string) ($row['message'] ?? ''), $signals);
        $body = $this->requestBodyValue($context);
        $payload = [
            'endpoint' => $endpoint !== '' ? $endpoint : ($signals['endpoint'] ?? ''),
            'requestBody' => $body,
            'id' => $row['id'] ?? null,
            'level' => $row['level'] ?? '',
            'source' => $row['source'] ?? '',
            'message' => $row['message'] ?? '',
            'at' => $row['at'] ?? '',
            'restaurant' => $row['restaurant'] ?? '',
            'restaurantId' => $row['restaurantId'] ?? '',
            'device' => $row['device'] ?? '',
            'deviceId' => $row['deviceId'] ?? '',
            'platform' => $row['platform'] ?? '',
            'version' => $row['version'] ?? '',
            'method' => $signals['method'] ?? '',
            'code' => $signals['code'] ?? '',
            'context' => $context,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : '{}';
    }

    private function stringify(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }
        if ($value === null || $value === []) {
            return null;
        }
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) > 96) {
            return mb_substr($text, 0, 93).'…';
        }

        return $text;
    }
}
