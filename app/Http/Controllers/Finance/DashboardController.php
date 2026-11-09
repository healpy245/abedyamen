<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceClientDirectory;
use App\Services\Finance\FinanceDashboard;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(FinanceClientDirectory $directory, FinanceDashboard $dashboard): View
    {
        $directory->syncIfStale();

        return view('workspace.finance.dashboard', [
            'page' => 'dashboard',
            'dash' => $dashboard->build(),
        ]);
    }
}
