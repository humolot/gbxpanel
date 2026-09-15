<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
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
