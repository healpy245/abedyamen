<?php

namespace App\Support;

use App\Actions\SignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A stale CSRF token would otherwise render Laravel's 419 page.
 * Login still completes. Every other browser form is sent back to a fresh page.
 */
class ExpiredPageRecovery
{
    public function respond(Request $request, bool $rotateSession = true): Response
    {
        if ($request->is('login') && $request->isMethod('POST')) {
            return app(SignIn::class)->attempt($request, $rotateSession);
        }

        if ($request->expectsJson() || $request->headers->has('X-Livewire')) {
            return new JsonResponse(['message' => 'expired'], 419);
        }

        return redirect()
            ->to($this->target($request))
            ->withInput($request->except(['password', 'password_confirmation', '_token']));
    }

    private function target(Request $request): string
    {
        $previous = url()->previous();

        if (! is_string($previous) || $previous === '' || $previous === $request->fullUrl()) {
            return route('login');
        }

        return $previous;
    }
}
