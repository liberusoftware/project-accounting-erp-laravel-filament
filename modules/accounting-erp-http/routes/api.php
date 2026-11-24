<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Liberu\Accounting\ErpHttp\Http\Controllers\Api\OpenApiController;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', fn (Request $request) => $request->user()->makeVisible('email'));

    // OpenAPI spec for the versioned API (public — no token needed to read the docs).
    Route::get('/v1/openapi.json', [OpenApiController::class, 'spec'])->withoutMiddleware('auth:sanctum');
});
