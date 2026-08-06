<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        dd('1');

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        dd('2');
        $exceptions->render(function (
            \Illuminate\Http\Exceptions\ThrottleRequestsException $e
        ) {

            return response()->json([
                'success' => false,
                'message' => 'Prea multe încercări de autentificare. Încearcă din nou peste un minut.',
            ], 429);

        });
    })->create();
