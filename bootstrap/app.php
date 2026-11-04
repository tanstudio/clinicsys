<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withSchedule(fn (Schedule $schedule) => $schedule->command('clinic:messages')->everyMinute()->withoutOverlapping(10))
    ->withMiddleware(fn (Middleware $middleware) => $middleware->validateCsrfTokens(except: ['webhooks/sleekflow']))
    ->withExceptions(fn (Exceptions $exceptions) => $exceptions->dontFlash([
        'name','email','phone','identity','address','emergency_name','emergency_phone','details','reason',
        'symptoms','notes','diagnosis','prescription','plan','tags','segment','note','body','reply','q',
    ]))
    ->create();
