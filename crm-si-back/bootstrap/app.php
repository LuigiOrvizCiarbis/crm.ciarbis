<?php

use App\Http\Middleware\EnsureTrialNotExpired;
use App\Http\Middleware\EnsureSectionAccess;
use App\Http\Middleware\SetSpatieTeamId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'section' => EnsureSectionAccess::class,
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'webhooks/*',
        ]);
        $middleware->appendToGroup('api', SetSpatieTeamId::class);
        $middleware->appendToGroup('api', EnsureTrialNotExpired::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            // Excepciones que Laravel ya maneja con status codes propios (422, 401, 403, etc.)
            if ($e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof HttpExceptionInterface) {
                return null;
            }

            // Cualquier otra excepción es un error interno: ocultamos los detalles
            // al cliente. El reporte (logs + Sentry) lo maneja la pipeline de
            // excepciones de Laravel vía Integration::handles(); no llamamos
            // report() acá para no duplicar el evento en Sentry.
            return new JsonResponse([
                'message' => 'Ocurrió un error interno. Inténtalo de nuevo más tarde.',
            ], 500);
        });

        Integration::handles($exceptions);
    })->create();
