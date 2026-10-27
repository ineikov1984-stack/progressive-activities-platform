<?php

use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InstructorProfileController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\OperatorOrganizationApplicationController;
use App\Http\Controllers\Api\V1\OrganizationApplicationController;
use App\Http\Controllers\Api\V1\OrganizationCatalogProfileController;
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
            Route::get('/invitations', [InvitationController::class, 'index'])->middleware('tenant');
            Route::post('/invitations/accept', [InvitationController::class, 'accept']);
            Route::get('/audit-events', [AuditEventController::class, 'index'])->middleware('tenant');
            Route::prefix('catalog')->middleware('tenant')->group(function (): void {
                Route::middleware('organization.access:operational.read')->group(function (): void {
                    Route::get('/profile', [OrganizationCatalogProfileController::class, 'show']);
                    Route::get('/locations', [LocationController::class, 'index']);
                    Route::get('/instructors', [InstructorProfileController::class, 'index']);
                });
                Route::middleware('organization.access:operational.write')->group(function (): void {
                    Route::patch('/profile', [OrganizationCatalogProfileController::class, 'update']);
                    Route::post('/locations', [LocationController::class, 'store']);
                    Route::patch('/locations/{location}', [LocationController::class, 'update']);
                    Route::post('/instructors', [InstructorProfileController::class, 'store']);
                    Route::patch('/instructors/{instructor}', [InstructorProfileController::class, 'update']);
                });
            });
            Route::middleware(['tenant', 'organization.access:onboarding'])->group(function (): void {
                Route::get('/organization-application', [OrganizationApplicationController::class, 'show']);
                Route::patch('/organization-application', [OrganizationApplicationController::class, 'update']);
                Route::post('/organization-application/submit', [OrganizationApplicationController::class, 'submit']);
            });
            Route::prefix('operator')->middleware('platform.operator')->group(function (): void {
                Route::get('/organization-applications', [OperatorOrganizationApplicationController::class, 'index']);
                Route::post('/organization-applications', [OperatorOrganizationApplicationController::class, 'store']);
                Route::post('/organization-applications/{organization}/review', [OperatorOrganizationApplicationController::class, 'review']);
            });
        });
    });
});
