<?php

use App\Http\Middleware\ApiAuthenticate;
use App\Services\Api\ApiCatalog;
use Illuminate\Support\Facades\Route;

/*
 * Management API.
 *
 * The routes come from the endpoint catalog, which also builds the OpenAPI document and the
 * documentation shown on the API page of the panel: one list, no drift between them.
 */
Route::prefix(ApiCatalog::VERSION)->middleware(ApiAuthenticate::class)->group(function () {
    foreach (ApiCatalog::endpoints() as $endpoint) {
        [$class, $method] = explode('@', $endpoint['action']);
        $uri = ltrim($endpoint['path'], '/') ?: '/';

        Route::match([$endpoint['method']], $uri, ['App\\Http\\Controllers\\Api\\'.$class.'Controller', $method])
            ->defaults('scope', $endpoint['scope'])
            ->name('api.'.ApiCatalog::VERSION.'.'.strtolower($endpoint['method']).'.'.trim(str_replace(['/', '{', '}'], ['.', '', ''], $endpoint['path']), '.'));
    }
});
