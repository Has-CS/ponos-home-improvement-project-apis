<?php

use App\Http\Middleware\ForceJsonResponse;
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
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        // Spatie permission middleware aliases
        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'team.global'        => \App\Http\Middleware\EnsureGlobalPermissionScope::class,
            'project.access'     => \App\Http\Middleware\EnsureProjectAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException $e) {
            return \App\Support\ApiResponse::error('Token has expired.', 401);
        });
        $exceptions->render(function (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException $e) {
            return \App\Support\ApiResponse::error('Token is invalid.', 401);
        });
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return \App\Support\ApiResponse::error('Unauthenticated.', 401);
            }
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previous = $e->getPrevious();

            if ($previous instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $model = class_basename($previous->getModel());

                return \App\Support\ApiResponse::error("{$model} not found.", 404);
            }

            return \App\Support\ApiResponse::error('The requested resource was not found.', 404);
        });
        $exceptions->render(function (\Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }

            // Intentional HTTP-status exceptions (e.g. Spatie's UnauthorizedException
            // for failed role:/permission: checks, which is a 403) must render with
            // their real status/message rather than being masked as a generic 500.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return \App\Support\ApiResponse::error($e->getMessage() ?: 'Request failed.', $e->getStatusCode());
            }

            return \App\Support\ApiResponse::error('Something went wrong. Please try again later.', 500);
        });
    })->create();
