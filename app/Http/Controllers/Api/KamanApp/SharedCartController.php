<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\KamanApp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharedCartController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $doc = $this->document($request);
        $code = $this->code($doc['code'] ?? null);
        if ($code === null) {
            return response()->json(['message' => 'Invalid cart code.'], 422);
        }

        $doc['code'] = $code;
        if ($this->read($code) !== null) {
            return response()->json(['message' => 'Cart code already exists.'], 409);
        }

        $this->write($code, $doc);

        return response()->json(['success' => true, 'data' => $doc]);
    }

    public function show(string $code): JsonResponse
    {
        $safe = $this->code($code);
        $doc = $safe === null ? null : $this->read($safe);
        if ($doc === null) {
            return response()->json(['message' => 'Cart not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $doc]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $safe = $this->code($code);
        if ($safe === null) {
            return response()->json(['message' => 'Invalid cart code.'], 422);
        }

        $doc = $this->document($request);
        $doc['code'] = $safe;
        $this->write($safe, $doc);

        return response()->json(['success' => true, 'data' => $doc]);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Request $request): array
    {
        $raw = $request->getContent();
        if (strlen($raw) > 8_000_000) {
            abort(413);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            abort(422, 'Cart body must be a JSON object.');
        }

        unset($decoded['success'], $decoded['data']);

        return $decoded;
    }

    private function code(mixed $value): ?string
    {
        $code = strtolower(trim((string) $value));
        if (! preg_match('/^[a-z0-9]{6,32}$/', $code)) {
            return null;
        }

        return $code;
    }

    private function path(string $code): string
    {
        return storage_path('app/private/shared-carts/'.$code.'.json');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $code): ?array
    {
        $path = $this->path($code);
        if (! is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return null;
            }
            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function write(string $code, array $doc): void
    {
        $dir = storage_path('app/private/shared-carts');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            abort(500, 'Could not store the shared cart.');
        }

        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            abort(422, 'Cart body could not be stored.');
        }

        $path = $this->path($code);
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            abort(500, 'Could not store the shared cart.');
        }
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            abort(500, 'Could not store the shared cart.');
        }
    }
}
