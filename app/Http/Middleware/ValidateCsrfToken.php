<?php

namespace App\Http\Middleware;

use App\Support\ExpiredPageRecovery;
use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;
use Illuminate\Session\TokenMismatchException;

class ValidateCsrfToken extends Middleware
{
    public function handle($request, Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException) {
            return app(ExpiredPageRecovery::class)->respond($request);
        }
    }

    protected function runningUnitTests()
    {
        if ($this->app->bound('csrf.enforce') && $this->app->make('csrf.enforce') === true) {
            return false;
        }

        return parent::runningUnitTests();
    }
}
