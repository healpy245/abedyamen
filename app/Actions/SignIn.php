<?php

namespace App\Actions;

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

    public function attempt(Request $request, bool $rotateSession = true): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('login')
                ->withErrors($validator)
                ->onlyInput('email');
        }

        if (Auth::attempt($validator->validated(), $request->boolean('remember'))) {
            if ($rotateSession && $request->hasSession()) {
                $request->session()->regenerate();
            }

            return redirect()->intended(route('home'));
        }

        return redirect()
            ->route('login')
            ->withErrors(['email' => __('auth.failed')])
            ->onlyInput('email');
    }
}
