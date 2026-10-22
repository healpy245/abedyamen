<?php

declare(strict_types=1);

namespace App\Http\Controllers\OfficeVision;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StatusController extends Controller
{
    private const CODE = '4545';

    public function index(Request $request): View
    {
        if ($request->session()->get('officevision_gate') === true) {
            return view('officevision.status');
        }

        return view('officevision.gate');
    }

    public function unlock(Request $request): RedirectResponse
    {
        $code = (string) $request->input('code', '');

        if (hash_equals(self::CODE, $code)) {
            $request->session()->put('officevision_gate', true);
        }

        return redirect()->route('officevision.gate');
    }
}
