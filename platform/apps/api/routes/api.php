<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\ActivityGroupController;
use App\Http\Controllers\Api\V1\ActivityPublicationController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogMediaController;
use App\Http\Controllers\Api\V1\InstructorProfileController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\OpenDayCampaignController;
use App\Http\Controllers\Api\V1\OperatorOrganizationApplicationController;
use App\Http\Controllers\Api\V1\OrganizationApplicationController;
use App\Http\Controllers\Api\V1\OrganizationCatalogProfileController;
use App\Http\Controllers\Api\V1\OrganizationContextController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ParentChildController;
use App\Http\Controllers\Api\V1\ParentEnrollmentController;
use App\Http\Controllers\Api\V1\PublicCatalogController;
use App\Http\Controllers\Api\V1\StaffEnrollmentChangeController;
use App\Http\Controllers\Api\V1\StaffEnrollmentController;
use App\Http\Controllers\Api\V1\VisitOfferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    Route::get('/public/organizations/{organizationSlug}/catalog', [PublicCatalogController::class, 'catalog']);
    Route::get('/public/organizations/{organizationSlug}/activities/{activitySlug}', [PublicCatalogController::class, 'activity']);
    Route::get('/public/organizations/{organizationSlug}/open-days/{campaignSlug}', [PublicCatalogController::class, 'openDay']);
    Route::get('/public/organizations/{organizationSlug}/media/{filename}', [PublicCatalogController::class, 'media'])
        ->where('filename', '[0-9a-f-]{36}\\.(?:jpg|png|webp)');

    Route::middleware('web')->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::get('/parent/children', [ParentChildController::class, 'index']);
            Route::post('/parent/children', [ParentChildController::class, 'store']);
            Route::get('/parent/children/{child}', [ParentChildController::class, 'show'])->whereUuid('child');
            Route::patch('/parent/children/{child}', [ParentChildController::class, 'update'])->whereUuid('child');
            Route::get('/parent/enrollment-applications', [ParentEnrollmentController::class, 'index']);
            Route::post('/parent/enrollment-applications', [ParentEnrollmentController::class, 'store']);
            Route::post('/parent/enrollment-applications/{application}/withdraw', [ParentEnrollmentController::class, 'withdraw'])->whereUuid('application');
            Route::post('/parent/enrollments/{enrollment}/end-from', [ParentEnrollmentController::class, 'endFrom'])->whereUuid('enrollment');
            Route::post('/parent/enrollments/{enrollment}/exclude-session', [ParentEnrollmentController::class, 'excludeSession'])->whereUuid('enrollment');
            Route::post('/parent/enrollments/{enrollment}/visit-outcome', [ParentEnrollmentController::class, 'visitOutcome'])->whereUuid('enrollment');
            Route::post('/enrollments/{enrollment}/visit-outcome', [ParentEnrollmentController::class, 'staffVisitOutcome'])
                ->middleware(['tenant', 'organization.access:operational.write'])->whereUuid('enrollment');
            Route::get('/organizations', [OrganizationController::class, 'index']);
            Route::post('/context/organization', [OrganizationContextController::class, 'select']);
            Route::get('/context/organization', [OrganizationContextController::class, 'current'])
                ->middleware('tenant');
            Route::post('/invitations', [InvitationController::class, 'store'])->middleware('tenant');
            Route::get('/invitations', [InvitationController::class, 'index'])->middleware('tenant');
            Route::post('/invitations/accept', [InvitationController::class, 'accept']);
            Route::get('/audit-events', [AuditEventController::class, 'index'])->middleware('tenant');
            Route::prefix('enrollment')->middleware('tenant')->group(function (): void {
                Route::middleware('organization.access:operational.read')->group(function (): void {
                    Route::get('/applications', [StaffEnrollmentController::class, 'applications']);
                    Route::get('/groups', [StaffEnrollmentController::class, 'groups']);
                    Route::get('/groups/{group}/roster', [StaffEnrollmentController::class, 'roster'])->whereUuid('group');
                    Route::get('/enrollments/{enrollment}/transfer-options', [StaffEnrollmentChangeController::class, 'transferOptions'])->whereUuid('enrollment');
                });
                Route::middleware('organization.access:operational.write')->group(function (): void {
                    Route::post('/applications/{application}/review', [StaffEnrollmentController::class, 'review'])->whereUuid('application');
                    Route::post('/enrollments/{enrollment}/transfer', [StaffEnrollmentChangeController::class, 'transfer'])->whereUuid('enrollment');
                    Route::post('/enrollments/{enrollment}/pauses', [StaffEnrollmentChangeController::class, 'pause'])->whereUuid('enrollment');
                    Route::post('/enrollments/{enrollment}/pauses/{pause}/end', [StaffEnrollmentChangeController::class, 'endPause'])->whereUuid(['enrollment', 'pause']);
                    Route::post('/enrollments/{enrollment}/end', [StaffEnrollmentChangeController::class, 'end'])->whereUuid('enrollment');
                    Route::post('/enrollments/{enrollment}/reopen', [StaffEnrollmentChangeController::class, 'reopen'])->whereUuid('enrollment');
                    Route::post('/enrollments/{enrollment}/skipped-sessions', [StaffEnrollmentChangeController::class, 'skipSession'])->whereUuid('enrollment');
                    Route::delete('/enrollments/{enrollment}/skipped-sessions/{session}', [StaffEnrollmentChangeController::class, 'restoreSession'])->whereUuid(['enrollment', 'session']);
                });
            });
            Route::prefix('catalog')->middleware('tenant')->group(function (): void {
                Route::middleware('organization.access:operational.read')->group(function (): void {
                    Route::get('/activities', [ActivityController::class, 'index']);
                    Route::get('/activities/{activity}', [ActivityController::class, 'show']);
                    Route::get('/activities/{activity}/groups', [ActivityGroupController::class, 'index']);
                    Route::get('/media/{filename}', [CatalogMediaController::class, 'show']);
                    Route::get('/profile', [OrganizationCatalogProfileController::class, 'show']);
                    Route::get('/locations', [LocationController::class, 'index']);
                    Route::get('/instructors', [InstructorProfileController::class, 'index']);
                    Route::get('/visit-offers', [VisitOfferController::class, 'index']);
                    Route::get('/visit-offers/{visitOffer}', [VisitOfferController::class, 'show']);
                    Route::get('/open-day-campaigns', [OpenDayCampaignController::class, 'index']);
                    Route::get('/open-day-campaigns/{campaign}', [OpenDayCampaignController::class, 'show']);
                });
                Route::middleware('organization.access:operational.write')->group(function (): void {
                    Route::post('/activities', [ActivityController::class, 'store']);
                    Route::patch('/activities/{activity}', [ActivityController::class, 'update']);
                    Route::post('/activities/{activity}/publish', [ActivityPublicationController::class, 'store']);
                    Route::post('/activities/{activity}/groups', [ActivityGroupController::class, 'store']);
                    Route::patch('/groups/{group}', [ActivityGroupController::class, 'update']);
                    Route::post('/media', [CatalogMediaController::class, 'store']);
                    Route::patch('/profile', [OrganizationCatalogProfileController::class, 'update']);
                    Route::post('/locations', [LocationController::class, 'store']);
                    Route::patch('/locations/{location}', [LocationController::class, 'update']);
                    Route::post('/instructors', [InstructorProfileController::class, 'store']);
                    Route::patch('/instructors/{instructor}', [InstructorProfileController::class, 'update']);
                    Route::post('/visit-offers', [VisitOfferController::class, 'store']);
                    Route::patch('/visit-offers/{visitOffer}', [VisitOfferController::class, 'update']);
                    Route::post('/visit-offers/{visitOffer}/publish', [VisitOfferController::class, 'publish']);
                    Route::post('/visit-offers/{visitOffer}/close', [VisitOfferController::class, 'close']);
                    Route::post('/visit-offers/{visitOffer}/cancel', [VisitOfferController::class, 'cancel']);
                    Route::post('/open-day-campaigns', [OpenDayCampaignController::class, 'store']);
                    Route::patch('/open-day-campaigns/{campaign}', [OpenDayCampaignController::class, 'update']);
                    Route::post('/open-day-campaigns/{campaign}/publish', [OpenDayCampaignController::class, 'publish']);
                    Route::post('/open-day-campaigns/{campaign}/close', [OpenDayCampaignController::class, 'close']);
                    Route::post('/open-day-campaigns/{campaign}/cancel', [OpenDayCampaignController::class, 'cancel']);
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
