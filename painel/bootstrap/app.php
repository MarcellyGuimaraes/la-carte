<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * O Render (como qualquer proxy que termina o HTTPS) repassa HTTP ao
         * container. Sem confiar no proxy, o Laravel monta URLs http:// e o
         * navegador bloqueia assets e requests do Livewire/Filament por mixed
         * content. 'at: *' confia em qualquer proxy — aceitável porque só o
         * proxy do Render fala com o container.
         */
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
