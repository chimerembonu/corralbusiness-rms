<?php

use App\Support\Tenancy\SetTenantFromUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetTenantFromUser::class,
        ]);
        // The tenant must be known BEFORE {order}, {job}, {session}… are looked up from the URL, so the
        // tenant scope applies to that lookup and another restaurant's record is a 404 (security review H4).
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenantFromUser::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
