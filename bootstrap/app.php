<?php

use App\Exceptions\HubException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Every API error as `{ error: AppErrorShape }` with a stable code (the contract's ErrorCode). */
$apiError = function (string $code, string $message, int $status, bool $retryable = false): JsonResponse {
    return response()->json(['error' => ['code' => $code, 'message' => $message, 'retryable' => $retryable]], $status);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Sessions are for the web UI (Phase 9); desktops send a token and no cookie.
        $middleware->validateCsrfTokens(except: ['login']);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $wantsJson = fn (Request $request) => $request->is('api/*', 'broadcasting/*', 'login', 'logout') || $request->expectsJson();
        $exceptions->shouldRenderJsonWhen($wantsJson);
        $exceptions->dontReport(HubException::class);

        $exceptions->render(function (Throwable $e, Request $request) use ($apiError, $wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return match (true) {
                $e instanceof HubException => response()->json(['error' => $e->shape()], $e->status),
                $e instanceof AuthenticationException => $apiError('hub_auth_required', 'Sign in first', 401),
                $e instanceof AuthorizationException => $apiError('forbidden', $e->getMessage() ?: 'Not allowed', 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $apiError('not_found', 'Not found', 404),
                $e instanceof ValidationException => $apiError('invalid_request', $e->getMessage(), 422),
                $e instanceof ThrottleRequestsException => $apiError('rate_limited', 'Too many attempts; try again shortly', 429, true),
                $e instanceof HttpExceptionInterface => $apiError(
                    $e->getStatusCode() === 403 ? 'forbidden' : ($e->getStatusCode() === 419 ? 'hub_auth_required' : 'invalid_request'),
                    $e->getMessage() ?: 'Request refused',
                    $e->getStatusCode(),
                ),
                default => $apiError('internal', config('app.debug') ? $e->getMessage() : 'Something went wrong on the hub', 500, true),
            };
        });
    })->create();
