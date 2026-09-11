<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

trait RendersAppDevelopmentModal
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function appDevelopmentModal(
        Request $request,
        string $view,
        array $data,
        string $title,
        string $variant = 'view',
        ?string $fallback = null,
    ): View {
        if ($this->wantsAppDevelopmentModal($request)) {
            return view($view, $data);
        }

        return view('app-development.layouts.modal-host', array_merge($data, [
            'modalBodyView' => $view,
            'modalTitle' => $title,
            'modalVariant' => $variant,
            'hidePageFlash' => true,
            'modalFallback' => $fallback ?? route('app-development.tickets.index'),
        ]));
    }

    protected function wantsAppDevelopmentModal(Request $request): bool
    {
        return $request->headers->get('X-App-Dev-Modal') === '1'
            || $request->boolean('modal');
    }
}
