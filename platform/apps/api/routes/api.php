<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\OrganizationContextController;
use App\Http\Controllers\Api\V1\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::middleware('web')->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::get('/organizations', [OrganizationController::class, 'index']);
            Route::post('/context/organization', [OrganizationContextController::class, 'select']);
            Route::get('/context/organization', [OrganizationContextController::class, 'current'])
                ->middleware('tenant');
            Route::post('/invitations', [InvitationController::class, 'store'])->middleware('tenant');
            Route::post('/invitations/accept', [InvitationController::class, 'accept']);
        });
    });
});
