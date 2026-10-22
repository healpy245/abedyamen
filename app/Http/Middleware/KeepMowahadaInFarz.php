<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mowahada stays on the green dashboard and their own bots.
 */
class KeepMowahadaInFarz
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->isMowahada()) {
            return $next($request);
        }

        if ($request->routeIs(
            'logout',
            'csrf.token',
            'lang.switch',
            'ai-chatbot.index',
            'ai-chatbot.farz.dashboard',
            'ai-chatbot.farz.numbers',
            'ai-chatbot.farz.numbers.store',
            'ai-chatbot.farz.numbers.check',
            'ai-chatbot.farz.numbers.status',
            'ai-chatbot.farz.numbers.warm',
            'ai-chatbot.farz.numbers.update',
            'ai-chatbot.farz.numbers.destroy',
            'ai-chatbot.farz.numbers.assign',
            'ai-chatbot.instances.*',
            'ai-chatbot.workspace.*',
        )) {
            return $next($request);
        }

        return redirect()->route('ai-chatbot.farz.dashboard');
    }
}
