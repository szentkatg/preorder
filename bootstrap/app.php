<?php

use App\Http\Middleware\EnsurePartnerPortalIsAvailable;
use App\Http\Middleware\RequirePartnerSeason;
use App\Http\Middleware\SetPartnerLocale;
use App\Support\PortalCsrfDiagnostics;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'partner.locale' => SetPartnerLocale::class,
            'partner.available' => EnsurePartnerPortalIsAvailable::class,
            'partner.season' => RequirePartnerSeason::class,
        ]);
        // Closed portal links should show the notice before auth redirects or
        // route-model binding, while still resolving the visitor's language.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, SetPartnerLocale::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsurePartnerPortalIsAvailable::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($response->getStatusCode() === 419) {
                PortalCsrfDiagnostics::record($request);
            }

            return $response;
        });
    })->create();
