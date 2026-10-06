<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\KamanApp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApkDownloadController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse|JsonResponse
    {
        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            return response()->json(['message' => 'Missing access token.'], 401);
        }

        $verified = $this->tokenBelongsToCustomer($token);
        if ($verified === false) {
            return response()->json(['message' => 'Could not verify the access token.'], 502);
        }
        if ($verified !== true) {
            return response()->json(['message' => 'Invalid access token.'], 401);
        }

        $path = (string) config('kaman-app.apk_path');
        if (! is_file($path)) {
            return response()->json(['message' => 'The app file is not published yet.'], 404);
        }

        return response()->download($path, (string) config('kaman-app.download_name'), [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return bool|null True for a signed-in customer, null when the token is rejected, false when kaman.rest cannot be reached.
     */
    private function tokenBelongsToCustomer(string $token): bool|null
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(12)
                ->get((string) config('kaman-app.customer_info_url'), ['lang' => 'en']);
        } catch (\Throwable) {
            return false;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            return null;
        }

        $customer = $response->json('data.customer');
        if (! is_array($customer)) {
            $customer = $response->json('data');
        }

        return is_array($customer);
    }
}
