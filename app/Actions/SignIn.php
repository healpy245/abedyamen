<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SignIn
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $this->attempt($request);
    }

    public function attempt(Request $request, bool $rotateSession = true, string $gate = 'kaman'): RedirectResponse
    {
        unset($gate);

        $loginRoute = 'login';

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route($loginRoute)
                ->withErrors($validator)
                ->onlyInput('email');
        }

        if (Auth::attemptWhen($validator->validated(), static fn (User $user): bool => ! $user->isTurnedOff(), $request->boolean('remember'))) {
            /** @var User|null $user */
            $user = Auth::user();

            if ($user?->isChatbotOnly()) {
                Auth::logout();

                return redirect()
                    ->route('login')
                    ->withErrors(['email' => __('auth.failed')])
                    ->onlyInput('email');
            }

            if ($rotateSession && $request->hasSession()) {
                $request->session()->regenerate();
            }

            return redirect()->intended($user?->afterLoginUrl() ?? route('login'));
        }

        return redirect()
            ->route($loginRoute)
            ->withErrors(['email' => __('auth.failed')])
            ->onlyInput('email');
    }
}
