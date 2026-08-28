<?php

use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare terminates TLS at its edge and proxies to this origin, so the
        // visitor's real IP and original scheme reach us only in X-Forwarded-*.
        // Untrusted, every request looks like plain HTTP from a Cloudflare address:
        // generated URLs and the JWT `iss` claim come out http:// on an https:// site,
        // and login_attempts.ip_address records Cloudflare instead of the caller.
        //
        // Cloudflare's published ranges rather than '*': '*' believes X-Forwarded-For
        // from ANY caller, which would let anyone choose the IP written to
        // login_attempts (AuthService::attempt) and the activity log — an audit trail
        // the sender controls. '*' is only safe when the origin cannot be reached
        // except through the proxy, and this box still answers on its bare IP.
        //
        // The list is a literal on purpose. This closure runs on
        // afterResolving(HttpKernel::class), which Application::handleRequest() does
        // BEFORE $kernel->handle() boots config and .env — so config()/env() would
        // both return null here. Refresh from https://www.cloudflare.com/ips/.
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

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

            // Anything reaching here is a genuine bug, and its message may carry
            // internals (SQL, paths, credentials), so it stays masked. Instead of
            // dropping the detail entirely, tag the failure with a correlation id:
            // the client can quote it and `grep <id> storage/logs/laravel.log`
            // lands on the entry holding the stack trace. Without this, every
            // unexpected fault is the same untraceable sentence.
            $reference = (string) \Illuminate\Support\Str::uuid();

            // The id goes in the MESSAGE, not just the context array, so one grep
            // finds it whatever the channel's context formatting does.
            \Illuminate\Support\Facades\Log::error("[{$reference}] " . $e->getMessage(), [
                'exception' => $e,
                'method'    => $request->method(),
                'path'      => $request->path(),
                // rescue(): resolving the user can itself throw on a malformed
                // token, and an exception raised INSIDE this handler would lose
                // the reference and mask the fault it exists to explain.
                'user_id'   => rescue(fn () => $request->user()?->id, null, report: false),
            ]);

            return \App\Support\ApiResponse::error(
                'Something went wrong. Please try again later.',
                500,
                null,
                $reference,
            );
        });
    })->create();
