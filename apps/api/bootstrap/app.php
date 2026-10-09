<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

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
        //
        // P12.5 — narrowed from the previous '*' (which trusted
        // X-Forwarded-* from literally any peer, including, in
        // principle, a request that reached php-fpm by some other path)
        // to the RFC1918 private-network ranges Docker's own bridge
        // networks always assign addresses from. Nginx is the only
        // container with any published port in docker-compose.prod.yml
        // (mysql/redis/api/queue/scheduler publish none at all — see
        // that file) and is always reached on the compose network, so
        // this is "trust the proxy that can actually reach us" without
        // needing to know this deployment's specific subnet, which
        // Compose assigns per-project and isn't fixed by this repo.
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

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

        // P12.1 — additive only: registers one more `reportable()`
        // callback alongside Laravel's own, so it runs through the exact
        // same report() pipeline (including Laravel's existing
        // $dontReport rules — ValidationException, AuthorizationException,
        // 404s, etc. — never reach Sentry as if they were crashes) and
        // never touches shouldRenderJsonWhen above or any HTTP response
        // shape. A no-op with no network activity at all when
        // SENTRY_LARAVEL_DSN/SENTRY_DSN is unset (every environment
        // except a deliberately configured production — see
        // config/sentry.php and .env.example).
        Integration::handles($exceptions);
    })->create();
