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
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SecurityEntrance::class,
        ]);
        // the entrance must run before "auth", otherwise guests get redirected to /login
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\SecurityEntrance::class,
        );
        $middleware->alias([
            'panel.access' => \App\Http\Middleware\PanelAccess::class,
            'client.access' => \App\Http\Middleware\ClientAccess::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('client', 'client/*') ? route('client.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('client', 'client/*') ? route('client.home') : route('home'));
        $middleware->trustProxies(at: '127.0.0.1');
        // file contents (code editor, config editors) must be saved byte for byte
        $middleware->trimStrings(except: ['content', 'value']);
        // read by Apache to allow phpMyAdmin/Adminer (see config gbx.tools)
        $middleware->encryptCookies(except: ['gbx_tools']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // AJAX calls always receive a JSON payload the frontend can toast.
        // the management API has its own envelope: {ok:false, error:{code, message}}
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = match (true) {
                $e instanceof \Illuminate\Validation\ValidationException => 422,
                $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
                $e instanceof \InvalidArgumentException, $e instanceof \RuntimeException => 422,
                default => 500,
            };
            $error = [
                'code' => match ($status) {
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    422 => $e instanceof \Illuminate\Validation\ValidationException ? 'validation_failed' : 'failed',
                    default => 'server_error',
                },
                'message' => $status === 404 ? 'This endpoint or resource does not exist.' : ($status === 500 ? 'The panel could not complete the request.' : $e->getMessage()),
            ];
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $error['message'] = (string) collect($e->errors())->flatten()->first();
                $error['fields'] = $e->errors();
            }
            if ($status === 500) {
                \App\Models\ActivityLog::record('api', 'API error: '.$request->path(), mb_substr($e->getMessage(), 0, 500));
            }

            return response()->json(['ok' => false, 'error' => $error, 'request_id' => $request->attributes->get('request_id')], $status);
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->expectsJson() || $e instanceof \Illuminate\Validation\ValidationException || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }
            $status = match (true) {
                $e instanceof \Illuminate\Session\TokenMismatchException => 419,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
                $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
                $e instanceof \InvalidArgumentException, $e instanceof \RuntimeException => 422,
                default => 500,
            };

            return response()->json(['ok' => false, 'message' => $status === 419 ? 'Session expired, reload the page.' : $e->getMessage()], $status);
        });
    })->create();
