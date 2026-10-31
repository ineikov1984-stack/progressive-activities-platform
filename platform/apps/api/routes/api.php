<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\ActivityGroupController;
use App\Http\Controllers\Api\V1\ActivityPublicationController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogMediaController;
use App\Http\Controllers\Api\V1\InstructorProfileController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
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
use App\Http\Controllers\Api\V1\PaymentTermsController;
use App\Http\Controllers\Api\V1\PublicCatalogController;
use App\Http\Controllers\Api\V1\ScheduleChangeController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\StaffEnrollmentChangeController;
use App\Http\Controllers\Api\V1\StaffEnrollmentContactController;
use App\Http\Controllers\Api\V1\StaffEnrollmentController;
use App\Http\Controllers\Api\V1\StaffEnrollmentDetailsController;
use App\Http\Controllers\Api\V1\TeachingController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\VisitOfferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    Route::get('/public/organizations/{organizationSlug}/catalog', [PublicCatalogController::class, 'catalog']);
    Route::get('/public/organizations/{organizationSlug}/activities/{activitySlug}', [PublicCatalogController::class, 'activity']);
    Route::get('/public/organizations/{organizationSlug}/open-days/{campaignSlug}', [PublicCatalogController::class, 'openDay']);
    Route::get('/public/organizations/{organizationSlug}/media/{filename}', [PublicCatalogController::class, 'media'])
        ->where('filename', '[0-9a-f-]{36}\\.(?:jpg|png|webp)');
    // An invitation, before any login (docs/product/team.md, т. 7.4): the token in the body, never in the path; the e-mail only for the e-mail's link.
    Route::post('/invitations/lookup', [InvitationAcceptanceController::class, 'lookup'])->middleware('throttle:30,1');

    Route::middleware('web')->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
        // A new profile from the invitation's e-mail: the same session and CSRF as the login.
        Route::post('/invitations/register', [InvitationAcceptanceController::class, 'register'])->middleware('throttle:10,1');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::patch('/me/contact', [AuthController::class, 'updateContact'])->middleware('throttle:30,1');
            // The groups I teach here, for every role with a teacher profile (docs/product/team.md, т. 8).
            Route::get('/me/teaching', [TeachingController::class, 'index'])->middleware(['tenant', 'organization.access:operational.read']);
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
            // The organization comes from the invitation, not from the session (docs/product/team.md, т. 7.4).
            Route::post('/invitations/join', [InvitationAcceptanceController::class, 'join'])->middleware('throttle:30,1');
            Route::get('/audit-events', [AuditEventController::class, 'index'])->middleware('tenant');
            // The team: people with or without a login (docs/product/team.md, т. 9).
            Route::prefix('team')->middleware('tenant')->group(function (): void {
                Route::middleware('organization.access:operational.read')->group(function (): void {
                    Route::get('/', [TeamController::class, 'index']);
                    Route::get('/members/{member}', [TeamController::class, 'show'])->whereUuid('member');
                    // The card "Права" (docs/product/team-rights.md, т. 9).
                    Route::get('/members/{member}/access', [TeamController::class, 'access'])->whereUuid('member');
                });
                Route::middleware('organization.access:operational.write')->group(function (): void {
                    Route::post('/members', [TeamController::class, 'store']);
                    Route::patch('/members/{member}', [TeamController::class, 'update'])->whereUuid('member');
                    Route::post('/members/{member}/archive', [TeamController::class, 'archive'])->whereUuid('member');
                    Route::post('/members/{member}/instructor-profile', [TeamController::class, 'linkProfile'])->whereUuid('member');
                    Route::post('/members/{member}/role', [TeamController::class, 'changeRole'])->whereUuid('member');
                    Route::post('/members/{member}/invitation', [TeamController::class, 'invite'])->whereUuid('member')->middleware('throttle:20,1');
                    Route::put('/members/{member}/access', [TeamController::class, 'saveAccess'])->whereUuid('member')->middleware('throttle:30,1');
                });
            });
            Route::prefix('enrollment')->middleware('tenant')->group(function (): void {
                Route::middleware('organization.access:operational.read')->group(function (): void {
                    Route::get('/applications', [StaffEnrollmentController::class, 'applications']);
                    // The enrolled children by name, for the filter "Дете" of the schedule (docs/product/schedule.md, т. 3.5);
                    // its own counter, not shared with the schedule and the details.
                    Route::get('/children', [StaffEnrollmentController::class, 'children'])->middleware('throttle:60,1,child-search');
                    Route::get('/groups', [StaffEnrollmentController::class, 'groups']);
                    Route::get('/groups/{group}/roster', [StaffEnrollmentController::class, 'roster'])->whereUuid('group');
                    Route::get('/enrollments/{enrollment}/transfer-options', [StaffEnrollmentChangeController::class, 'transferOptions'])->whereUuid('enrollment');
                    // The "Подробности" panel; contacts inside (docs/product/enrollment-contacts-and-emails.md, т. 8).
                    Route::get('/applications/{application}', [StaffEnrollmentDetailsController::class, 'application'])->whereUuid('application')->middleware('throttle:60,1');
                    Route::get('/enrollments/{enrollment}', [StaffEnrollmentDetailsController::class, 'enrollment'])->whereUuid('enrollment')->middleware('throttle:60,1');
                    // "Покажи телефона": a POST, written down, never cached; its own limit per hour and day (docs/product/team-rights.md, т. 6).
                    Route::post('/applications/{application}/contact/reveal', [StaffEnrollmentContactController::class, 'application'])->whereUuid('application')->middleware('throttle:60,1');
                    Route::post('/enrollments/{enrollment}/contact/reveal', [StaffEnrollmentContactController::class, 'enrollment'])->whereUuid('enrollment')->middleware('throttle:60,1');
                });
                Route::middleware('organization.access:operational.write')->group(function (): void {
                    Route::post('/applications/{application}/review', [StaffEnrollmentController::class, 'review'])->whereUuid('application');
                    Route::patch('/groups/{group}/approval', [StaffEnrollmentController::class, 'updateApproval'])->whereUuid('group');
                    Route::post('/enrollments/{enrollment}/transfer', [StaffEnrollmentChangeController::class, 'transfer'])->whereUuid('enrollment');
                    Route::post('/enrollments/{enrollment}/pauses', [StaffEnrollmentChangeController::class, 'pause'])->whereUuid('enrollment');
                    Route::post('/enrollments/{enrollment}/pauses/{pause}/end', [StaffEnrollmentChangeController::class, 'endPause'])->whereUuid(['enrollment', 'pause']);
                    Route::post('/enrollments/{enrollment}/end', [StaffEnrollmentChangeController::class, 'end'])->whereUuid('enrollment');
                    Route::post('/enrollments/{enrollment}/reopen', [StaffEnrollmentChangeController::class, 'reopen'])->whereUuid('enrollment');
                    Route::post('/enrollments/{enrollment}/skipped-sessions', [StaffEnrollmentChangeController::class, 'skipSession'])->whereUuid('enrollment');
                    Route::delete('/enrollments/{enrollment}/skipped-sessions/{session}', [StaffEnrollmentChangeController::class, 'restoreSession'])->whereUuid(['enrollment', 'session']);
                });
            });
            Route::prefix('schedule')->middleware('tenant')->group(function (): void {
                // "График" (docs/product/schedule.md, т. 6): the sessions of the person's scope and the panel of one; read only, no audit.
                Route::middleware(['organization.access:operational.read', 'throttle:120,1'])->group(function (): void {
                    Route::get('/', [ScheduleController::class, 'index']);
                    Route::get('/sessions/{session}', [ScheduleController::class, 'show'])->whereUuid('session');
                });
                // Changes of the schedule (docs/product/schedule-changes.md, т. 7.1): the team with "schedule.manage" for the
                // whole organization; the preview reads only, the change has its key for a repeat. Counters of their own.
                Route::middleware('organization.access:operational.write')->group(function (): void {
                    Route::post('/changes/preview', [ScheduleChangeController::class, 'preview'])->middleware('throttle:60,1,schedule-preview');
                    Route::post('/changes', [ScheduleChangeController::class, 'store'])->middleware('throttle:30,1,schedule-change');
                });
            });
            // Sets of payment terms (docs/product/payment-policies.md, т. 7.1): the team reads, owner and administrator change.
            Route::prefix('payment-terms')->middleware('tenant')->group(function (): void {
                Route::get('/', [PaymentTermsController::class, 'index'])->middleware('organization.access:operational.read');
                Route::post('/', [PaymentTermsController::class, 'store'])->middleware(['organization.access:operational.write', 'throttle:30,1']);
                Route::patch('/{terms}', [PaymentTermsController::class, 'update'])->whereUuid('terms')->middleware(['organization.access:operational.write', 'throttle:30,1']);
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
                    // "Махни от страницата", also on a published activity (docs/product/teacher-content.md, т. 5.6).
                    Route::post('/activities/{activity}/gallery/remove', [ActivityController::class, 'removeGalleryPhoto'])->whereUuid('activity');
                    Route::post('/activities/{activity}/groups', [ActivityGroupController::class, 'store']);
                    Route::patch('/groups/{group}', [ActivityGroupController::class, 'update']);
                    // Who leads or assists a group, also a published one (docs/product/team.md, т. 5).
                    Route::post('/groups/{group}/assignments', [ActivityGroupController::class, 'assign'])->whereUuid('group');
                    Route::post('/groups/{group}/assignments/{instructor}/end', [ActivityGroupController::class, 'endAssignment'])->whereUuid(['group', 'instructor']);
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
