<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API is only ever reached through the Nginx reverse proxy (see
        // infrastructure/nginx), never directly — trust it so Laravel reads
        // the real client IP/scheme from X-Forwarded-* instead of seeing
        // every request as HTTP from Nginx's own address.
        $middleware->trustProxies(at: '*');

        // Next.js and the API are served from the same Nginx origin (see
        // docs/PRODUCTION_ARCHITECTURE.md) — Sanctum's SPA mode lets the
        // browser authenticate with a normal session cookie against /api/*
        // routes instead of needing a bearer token. See config/sanctum.php
        // for the "stateful" domains this actually applies to.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
