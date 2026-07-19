<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatLoginController extends Controller
{
    /**
     * Show the ChatGPT-style login assistant page.
     */
    public function index()
    {
        return view('chat-login');
    }

    /**
     * Handle chat messages and drive the simple login flow.
     *
     * Conversation state is stored in the session under "chat_login_state" with:
     * - step: "ask_restaurant" | "ask_password"
     * - restaurant_name: string|null
     */
    public function message(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $userMessage = trim($validated['message']);

        $state = $request->session()->get('chat_login_state', [
            'step' => 'ask_restaurant',
            'restaurant_name' => null,
        ]);

        $step = $state['step'] ?? 'ask_restaurant';

        // Step 1: collect restaurant name
        if ($step === 'ask_restaurant') {
            $state['restaurant_name'] = $userMessage;
            $state['step'] = 'ask_password';
            $request->session()->put('chat_login_state', $state);

            return response()->json([
                'success' => true,
                'reply' => "Great, I noted the restaurant as \"{$state['restaurant_name']}\". Now please enter the password you use in Kaman.",
                'step' => $state['step'],
            ]);
        }

        // Step 2: collect password and attempt login
        if ($step === 'ask_password') {
            $restaurantName = (string) ($state['restaurant_name'] ?? '');
            $password = $userMessage;

            if ($restaurantName === '') {
                // Safety fallback – restart flow cleanly
                $request->session()->forget('chat_login_state');

                return response()->json([
                    'success' => false,
                    'reply' => 'I lost track of the restaurant name. Please tell me the restaurant name again.',
                    'step' => 'ask_restaurant',
                ]);
            }

            $loginResult = $this->attemptKamanLogin($restaurantName, $password);

            if (!$loginResult['success']) {
                // Stay on password step so the user can retry
                $state['step'] = 'ask_password';
                $request->session()->put('chat_login_state', $state);

                return response()->json([
                    'success' => true,
                    'reply' => "Login failed for \"{$restaurantName}\". Please check the restaurant name and password and try again.\n\nDetails: " . $loginResult['message'],
                    'step' => $state['step'],
                    'login_success' => false,
                ]);
            }

            // On success, clear state and confirm to user
            $request->session()->forget('chat_login_state');

            return response()->json([
                'success' => true,
                'reply' => "You're logged in to Kaman for \"{$restaurantName}\". I saved your token, so you can now run the Full AI automation from the main form.",
                'step' => 'done',
                'login_success' => true,
            ]);
        }

        // Fallback: reset the flow
        $request->session()->forget('chat_login_state');

        return response()->json([
            'success' => true,
            'reply' => 'Let’s start again. What is your restaurant name as it appears in Kaman?',
            'step' => 'ask_restaurant',
        ]);
    }

    /**
     * Attempt to login to Kaman using the same pattern as FormController::loginFullAi.
     *
     * @return array{success:bool,message:string}
     */
    private function attemptKamanLogin(string $restaurantName, string $password): array
    {
        $subdomain = $this->fullAiSubdomain($restaurantName);
        $baseUrl = "https://{$subdomain}.kaman.rest";

        $http = Http::timeout(30)->acceptJson();
        if (!config('services.kaman.ssl_verify', true)) {
            $http = $http->withoutVerifying();
        }

        try {
            $response = $http->post("{$baseUrl}/api/manager/login", [
                'email' => "{$subdomain}@kaman.rest",
                'password' => $password,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Chat login Kaman request failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Network error while contacting Kaman: ' . $e->getMessage(),
            ];
        }

        if (!$response->successful()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? $body['error'] ?? $response->body()) : $response->body();
            $message = is_string($message) ? $message : json_encode($message);

            Log::warning('Chat login Kaman failed', ['status' => $response->status(), 'response' => $message]);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $data = $response->json();
        $token = $data['token'] ?? $data['access_token'] ?? $data['data']['token'] ?? null;

        if ($token === null || $token === '') {
            return [
                'success' => false,
                'message' => 'Login succeeded but no token was returned from Kaman.',
            ];
        }

        session([
            'full_ai_kaman_token' => $token,
            'full_ai_kaman_base_url' => $baseUrl,
        ]);

        return [
            'success' => true,
            'message' => 'Login successful and token stored.',
        ];
    }

    private function fullAiSubdomain(string $name): string
    {
        $subdomain = strtolower(trim($name));
        $subdomain = preg_replace('/[^a-z0-9\-]/', '-', $subdomain);
        $subdomain = trim($subdomain, '-');
        $subdomain = (string) preg_replace('/-+/', '-', $subdomain);

        return $subdomain !== '' ? $subdomain : 'default';
    }
}

