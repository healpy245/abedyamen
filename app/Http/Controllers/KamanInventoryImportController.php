<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\KamanUrl;
use App\Services\Kaman\KamanInventoryPlanBuilder;
use App\Services\Kaman\KamanInventoryPlanExecutor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use RuntimeException;

final class KamanInventoryImportController extends Controller
{
    private const SESSION_PLAN = 'kaman_inventory_plan';

    private const SESSION_AUTH = 'kaman_inventory_auth';

    private const SESSION_RESOLVED = 'kaman_inventory_resolved';

    public function index(): View
    {
        return view('kaman-inventory-import', [
            'defaultSubdomain' => 'thex',
            'defaultEnvironment' => 'dev',
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subdomain' => 'required|string|max:64',
            'environment' => 'required|string|in:dev,rest',
            'email' => 'nullable|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        $subdomain = KamanUrl::normalizeSubdomain($validated['subdomain']);
        $tld = $validated['environment'] === 'dev' ? 'dev' : 'rest';
        $baseUrl = KamanUrl::managerApi($subdomain, $tld);
        $email = $validated['email'] ?? KamanUrl::loginEmail($subdomain);

        $http = Http::timeout(45)->acceptJson();
        if (!config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }

        try {
            $response = $http->post(KamanUrl::join($baseUrl, '/login'), [
                'email' => $email,
                'password' => $validated['password'],
            ]);
        } catch (ConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not reach Kaman API: ' . $e->getMessage(),
            ], 503);
        }

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => $response->json('message') ?? 'Login failed.',
                'body' => $response->json(),
            ], 401);
        }

        $token = $response->json('data.token') ?? $response->json('token');
        if (!is_string($token) || $token === '') {
            return response()->json([
                'success' => false,
                'message' => 'Login succeeded but no token was returned.',
            ], 502);
        }

        Session::put(self::SESSION_AUTH, [
            'token' => $token,
            'base_url' => $baseUrl,
            'email' => $email,
            'subdomain' => $subdomain,
            'environment' => $tld,
        ]);
        Session::forget([self::SESSION_PLAN, self::SESSION_RESOLVED]);

        return response()->json([
            'success' => true,
            'message' => "Logged in to {$subdomain}.kaman.{$tld} (manager API)",
            'base_url' => $baseUrl,
        ]);
    }

    public function buildPlan(Request $request, KamanInventoryPlanBuilder $builder): JsonResponse
    {
        if (!Session::has(self::SESSION_AUTH)) {
            return response()->json([
                'success' => false,
                'message' => 'Login first.',
            ], 401);
        }

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:5000',
            'skip_stock' => 'nullable|boolean',
            'skip_links' => 'nullable|boolean',
            'no_ingredients' => 'nullable|boolean',
            'no_recipe_links' => 'nullable|boolean',
            'suppliers_map' => 'nullable|string',
        ]);

        $suppliersPath = null;
        if (!empty($validated['suppliers_map'])) {
            $decoded = json_decode($validated['suppliers_map'], true);
            if (!is_array($decoded)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Suppliers map must be valid JSON object (name → UUID).',
                ], 422);
            }
            $suppliersPath = storage_path('app/kaman-suppliers-map-' . Session::getId() . '.json');
            file_put_contents($suppliersPath, json_encode($decoded, JSON_UNESCAPED_UNICODE));
        }

        try {
            $plan = $builder->build([
                'limit' => (int) ($validated['limit'] ?? 50),
                'skip_stock' => (bool) ($validated['skip_stock'] ?? false),
                'skip_links' => (bool) ($validated['skip_links'] ?? false),
                'no_ingredients' => (bool) ($validated['no_ingredients'] ?? false),
                'no_recipe_links' => (bool) ($validated['no_recipe_links'] ?? false),
                'suppliers_map_path' => $suppliersPath,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        Session::put(self::SESSION_PLAN, $plan);
        Session::put(self::SESSION_RESOLVED, []);

        return response()->json([
            'success' => true,
            'summary' => $plan['summary'] ?? [],
            'steps' => $plan['steps'] ?? [],
        ]);
    }

    public function executeStep(Request $request): JsonResponse
    {
        $auth = Session::get(self::SESSION_AUTH);
        $plan = Session::get(self::SESSION_PLAN);
        if (!is_array($auth) || !is_array($plan)) {
            return response()->json([
                'success' => false,
                'message' => 'Build a plan and login first.',
            ], 401);
        }

        $validated = $request->validate([
            'step_index' => 'required|integer|min:0',
        ]);

        $steps = $plan['steps'] ?? [];
        $index = (int) $validated['step_index'];
        if (!isset($steps[$index]) || !is_array($steps[$index])) {
            return response()->json([
                'success' => false,
                'message' => 'Step not found.',
            ], 404);
        }

        $executor = new KamanInventoryPlanExecutor(
            (string) $auth['base_url'],
            (string) $auth['token'],
            (string) $auth['email'],
            '',
        );
        $executor->mergeResolved(Session::get(self::SESSION_RESOLVED, []));

        $result = $executor->executeStep($steps[$index]);
        $steps[$index]['status'] = ($result['success'] ?? false) ? 'success' : 'failed';
        $steps[$index]['http_status'] = $result['status'] ?? 0;
        $steps[$index]['response'] = $result['body'] ?? null;
        $steps[$index]['executed_at'] = now()->toIso8601String();
        if (!empty($result['message'])) {
            $steps[$index]['result_message'] = $result['message'];
        }
        if (!empty($result['skipped'])) {
            $steps[$index]['status'] = 'skipped';
        }

        $plan['steps'] = $steps;
        Session::put(self::SESSION_PLAN, $plan);
        Session::put(self::SESSION_RESOLVED, $executor->resolvedIds());

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'step_index' => $index,
            'step' => $steps[$index],
            'result' => $result,
        ]);
    }

    public function clearPlan(): JsonResponse
    {
        Session::forget([self::SESSION_PLAN, self::SESSION_RESOLVED]);

        return response()->json(['success' => true]);
    }

}
