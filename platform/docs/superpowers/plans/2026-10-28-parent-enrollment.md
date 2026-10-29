# Parent Profiles and Enrollment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build secure parent and child profiles plus trial, single-session and full-period enrollment with approval, waitlisting, capacity protection, cancellation and role-specific interfaces.

**Architecture:** Add an `Enrollment` domain to the Laravel modular monolith. Children are parent-owned global records; organization-scoped applications, enrollments and immutable transitions use tenant RLS and reference existing catalog groups, sessions and prices. Next.js receives a parent workspace and an organization queue, while the public activity page hands authenticated users into the enrollment flow.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL RLS, PHPUnit, Next.js 16, React 19, TypeScript 5.9, Vitest, Playwright, Docker Compose.

**Spec:** `platform/docs/superpowers/specs/2026-10-28-parent-enrollment-design.md`

## Global Constraints

- Keep the existing `Activity → Offering → Group → Session` catalog as schedule source.
- Full-period enrollment is one durable enrollment, not one reservation per session.
- `monthly` and `package` are price/payment choices for full-period enrollment, not attendance scopes.
- Full-period enrollment never implies full advance payment unless its price terms explicitly say so.
- Money remains integer euro cents with currency `EUR`.
- Children are parent-owned and not tenant-owned; organizations gain scoped access only through applications/enrollments.
- Do not collect national identifiers, identity documents, diagnoses or free-form medical data.
- Every organization-scoped write enforces tenant context, authorization and PostgreSQL RLS.
- Public reason and internal note remain separate and never cross API boundaries.
- No UI may claim payment, email or push delivery works in this phase.
- Do not overwrite or commit the existing unrelated login-page change or `.pnpm-store/`.

## Review Focus

- Two approvals racing for the final place: one succeeds and the other follows full/waitlist behavior; Task 4 pins this.
- Parent guessing another child's UUID: every read/write returns 404; Task 2 pins this.
- Staff switching tenants with a known foreign UUID: application scopes and PostgreSQL RLS both hide it; Task 5 pins this.
- Repeated submit/approve after a retry: exactly one application/enrollment/transition; Tasks 3–4 pin this.
- Schedule regeneration after mid-period join/exit: past applicability stays historical and only future applicability changes; Task 4 pins this.

---

## File Map

- `app/Domain/Enrollment/Models/*` — child, application, enrollment, transition, session exception and future-notification event records.
- `app/Domain/Enrollment/Enums/*` — application status, enrollment status, scope and source.
- `app/Domain/Enrollment/Actions/*` — submit, review and change operations.
- `app/Domain/Enrollment/Services/*` — capacity and session applicability.
- `app/Http/Controllers/Api/V1/Parent*` — parent-facing APIs.
- `app/Http/Controllers/Api/V1/OrganizationEnrollmentController.php` — tenant queue and decisions.
- `database/migrations/2026_10_28_0001*.php` — schema and RLS.
- `src/features/enrollment/*` — web API, flow, parent workspace and admin queue.
- `src/app/enroll/*`, `src/app/parent/*`, `src/app/dashboard/enrollments/*` — routes.

## Task 1: Enrollment Persistence and Domain Types

**Files:**
- Create: `platform/apps/api/database/migrations/2026_10_28_000100_create_enrollment_tables.php`
- Create: `platform/apps/api/app/Domain/Enrollment/Enums/{EnrollmentApplicationStatus,EnrollmentStatus,EnrollmentScope,EnrollmentSource}.php`
- Create: `platform/apps/api/app/Domain/Enrollment/Models/{Child,EnrollmentApplication,Enrollment,EnrollmentTransition,EnrollmentSessionException,EnrollmentEvent}.php`
- Modify: `platform/apps/api/app/Domain/Identity/Models/User.php`
- Modify: `platform/apps/api/app/Domain/Catalog/Models/{Group,Session}.php`
- Test: `platform/apps/api/tests/Feature/Enrollment/EnrollmentSchemaTest.php`

**Interfaces:**
- Consumes: existing UUID users, organizations, groups, sessions and price options.
- Produces: the six models above, group enrollment settings and exact enum values from the approved spec.

- [ ] **Step 1: Write the failing schema test**

Assert UUID keys, relationships, integer version defaults, server-derived organization links, unique idempotency constraints and database rejection of invalid enum values. Assert children have `owner_user_id`, never `organization_id`.

- [ ] **Step 2: Run it and confirm failure**

Run: `docker compose run --rm --no-deps api php artisan test tests/Feature/Enrollment/EnrollmentSchemaTest.php`

Expected: FAIL because enrollment tables/classes do not exist.

- [ ] **Step 3: Add exact string-backed enums**

Application: `draft|submitted|waitlisted|approved|rejected|withdrawn|expired`; enrollment: `active|paused|ended|cancelled`; scope: `trial|single|full_period`; source: `organization_catalog|direct_link|marketplace|staff`.

- [ ] **Step 4: Add focused tables and relationships**

Applications/enrollments/transitions/session exceptions/events carry `organization_id`; children do not. Add positive-version checks, one exception per enrollment/session and one idempotency key per actor/source submission. Add `enrollment_approval_mode` (`manual|automatic`) and `waitlist_enabled` to groups with safe defaults `manual` and `true`.

- [ ] **Step 5: Run the test**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add platform/apps/api/database/migrations/2026_10_28_000100_create_enrollment_tables.php platform/apps/api/app/Domain/Enrollment platform/apps/api/app/Domain/Identity/Models/User.php platform/apps/api/app/Domain/Catalog/Models platform/apps/api/tests/Feature/Enrollment/EnrollmentSchemaTest.php
git commit -m "feat: add enrollment domain model"
```

## Task 2: Parent-Owned Children API

**Files:**
- Create: `platform/apps/api/app/Domain/Enrollment/Policies/EnrollmentPolicy.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/ParentChildController.php`
- Modify: `platform/apps/api/app/Providers/AppServiceProvider.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Enrollment/ParentChildTest.php`

**Interfaces:**
- Consumes: `Child` and authenticated Sanctum user.
- Produces: `GET/POST/PATCH /api/v1/parent/children`; `EnrollmentPolicy::viewChild(User, Child): bool` and `manageChild(User, Child): bool`.

- [ ] **Step 1: Write failing ownership and validation tests**

Cover list/create/update/archive, required names, valid optional birth date and school/class/group fields, ignored submitted owner IDs and 404 for another parent's UUID on read/write.

- [ ] **Step 2: Run and confirm failure**

Run: `docker compose run --rm --no-deps api php artisan test tests/Feature/Enrollment/ParentChildTest.php`

Expected: FAIL with missing routes/controllers.

- [ ] **Step 3: Implement policy and controller**

Add `index`, `store`, `show`, `update`. Resolve records through `$request->user()->children()` so foreign UUIDs are indistinguishable from missing records.

- [ ] **Step 4: Register authenticated parent routes and policy**

Use existing `web` + `auth:sanctum`; child management does not require tenant context.

- [ ] **Step 5: Run focused and identity regression tests**

Run: `docker compose run --rm --no-deps api php artisan test tests/Feature/Enrollment/ParentChildTest.php tests/Feature/Identity/AuthenticationTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add platform/apps/api/app/Domain/Enrollment/Policies/EnrollmentPolicy.php platform/apps/api/app/Http/Controllers/Api/V1/ParentChildController.php platform/apps/api/app/Providers/AppServiceProvider.php platform/apps/api/routes/api.php platform/apps/api/tests/Feature/Enrollment/ParentChildTest.php
git commit -m "feat: add secure parent child profiles"
```

## Task 3: Parent Enrollment Submission

**Files:**
- Create: `platform/apps/api/app/Domain/Enrollment/Actions/SubmitEnrollmentApplication.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/ParentEnrollmentController.php`
- Modify: `platform/apps/api/app/Http/Controllers/Api/V1/PublicCatalogController.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Enrollment/ParentEnrollmentApplicationTest.php`
- Test: `platform/apps/api/tests/Feature/Catalog/PublicCatalogTest.php`

**Interfaces:**
- Consumes: owned child, published group, price, optional concrete session, scope and `Idempotency-Key`.
- Produces: `SubmitEnrollmentApplication::handle(User $actor, array $payload, string $idempotencyKey): EnrollmentApplication`; parent list/submit/withdraw endpoints; public stable group/session IDs.

- [ ] **Step 1: Write failing submission tests**

Trial/single require a future session in the group; full-period requires none; monthly/package map to full-period; unpublished/foreign/suspended resources fail; terms version/time persist; foreign child is 404; repeated key returns original application; submitted organization IDs are ignored.

- [ ] **Step 2: Run and confirm failure**

Run: `docker compose run --rm --no-deps api php artisan test tests/Feature/Enrollment/ParentEnrollmentApplicationTest.php`

Expected: FAIL because submission is absent.

- [ ] **Step 3: Implement transactional submission**

Derive organization, scope, source and parent server-side. Store price/session references, accepted terms snapshot and idempotency key.

- [ ] **Step 4: Implement `index`, `store`, `withdraw`**

Withdrawal is valid only from submitted/waitlisted, increments version and creates one immutable transition.

- [ ] **Step 5: Add enrollment-safe public IDs**

Expose group ID, price-option ID and eligible future session ID/start/end/status only; never expose unpublished resources or internal organization IDs.

- [ ] **Step 6: Run submission and public-catalog tests**

Run: `docker compose run --rm --no-deps api php artisan test tests/Feature/Enrollment/ParentEnrollmentApplicationTest.php tests/Feature/Catalog/PublicCatalogTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add platform/apps/api/app/Domain/Enrollment/Actions/SubmitEnrollmentApplication.php platform/apps/api/app/Http/Controllers/Api/V1/ParentEnrollmentController.php platform/apps/api/app/Http/Controllers/Api/V1/PublicCatalogController.php platform/apps/api/routes/api.php platform/apps/api/tests/Feature/Enrollment/ParentEnrollmentApplicationTest.php platform/apps/api/tests/Feature/Catalog/PublicCatalogTest.php
git commit -m "feat: submit parent enrollment applications"
```

## Task 4: Capacity, Approval, Waitlist and Applicability

**Files:**
- Create: `platform/apps/api/app/Domain/Enrollment/Services/{EnrollmentCapacity,SessionApplicability}.php`
- Create: `platform/apps/api/app/Domain/Enrollment/Actions/{ReviewEnrollmentApplication,ChangeEnrollment}.php`
- Modify: `platform/apps/api/app/Domain/Enrollment/Actions/SubmitEnrollmentApplication.php`
- Modify: `platform/apps/api/app/Http/Controllers/Api/V1/ParentEnrollmentController.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Enrollment/EnrollmentLifecycleTest.php`
- Test: `platform/apps/api/tests/Unit/Enrollment/SessionApplicabilityTest.php`

**Interfaces:**
- Consumes: application, group/session capacity, decision, reasons and expected version.
- Produces: `EnrollmentCapacity::remainingForGroup(Group, ?CarbonImmutable): ?int`, `remainingForSession(Session): ?int`, `SessionApplicability::sessionsFor(Enrollment): Collection`, review/change actions and future-notification events that claim no delivery.

- [ ] **Step 1: Write failing applicability tests**

Pin trial/single to one session; full-period to sessions within boundaries; exclude cancelled sessions and one-date exceptions; preserve past applicability after future end.

- [ ] **Step 2: Write failing lifecycle tests**

Cover approval, rejection reason, waitlist, repeated approval, stale version, full group, automatic approval, disabled waitlist, two-transaction final-place race, mid-period join, future end, one-date exclusion, atomic transfer and exactly one undelivered event per state change.

- [ ] **Step 3: Run and confirm failure**

Run: `docker compose run --rm --no-deps api php artisan test tests/Unit/Enrollment/SessionApplicabilityTest.php tests/Feature/Enrollment/EnrollmentLifecycleTest.php`

Expected: FAIL with missing services/actions.

- [ ] **Step 4: Implement applicability and capacity services**

Count overlapping active full-period enrollments at group level and trial/single at session level. Return `null` for unlimited capacity. Query existing sessions instead of generating reservations.

- [ ] **Step 5: Implement transactional review**

Lock application/group, verify version/status, recompute capacity, create at most one enrollment and one transition. When full, apply configured waitlist behavior without partial writes. Extend submission so `automatic` groups use the same locked review action immediately; `manual` groups remain submitted.

- [ ] **Step 6: Implement future end, exception and transfer**

Require effective date/reason, never delete history, and link transfer source/target enrollments.

Also extend `ParentEnrollmentController` and `routes/api.php` with parent-owned `endFrom` and `excludeSession` endpoints. Parent ownership and organization cancellation policy decide whether the action applies immediately or creates a reviewable request.

- [ ] **Step 7: Run focused tests**

Run the Step 3 command. Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add platform/apps/api/app/Domain/Enrollment/Services platform/apps/api/app/Domain/Enrollment/Actions platform/apps/api/app/Http/Controllers/Api/V1/ParentEnrollmentController.php platform/apps/api/routes/api.php platform/apps/api/tests/Unit/Enrollment platform/apps/api/tests/Feature/Enrollment/EnrollmentLifecycleTest.php
git commit -m "feat: enforce enrollment capacity and lifecycle"
```

## Task 5: Organization Queue, Tenant RLS and Audit

**Files:**
- Create: `platform/apps/api/database/migrations/2026_10_28_000200_enable_enrollment_rls.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/OrganizationEnrollmentController.php`
- Modify: `platform/apps/api/app/Domain/Enrollment/Policies/EnrollmentPolicy.php`
- Modify: `platform/apps/api/app/Domain/Audit/Enums/AuditAction.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Enrollment/{EnrollmentTenantIsolationTest,OrganizationEnrollmentQueueTest}.php`
- Test: `platform/apps/api/tests/Feature/Audit/AuditTrailTest.php`

**Interfaces:**
- Consumes: Task 4 actions, existing `TenantContext`, roles and `AuditRecorder`.
- Produces: tenant-scoped list/show/create-for-parent/review/end/exclude/transfer endpoints and redacted audit events.

- [ ] **Step 1: Write failing tenant and authorization tests**

Pin owner/admin/coordinator capabilities, viewer/finance/instructor restrictions, suspended organization rejection, foreign UUID 404 after tenant switch, direct-query RLS hiding and inability of the application PostgreSQL role to bypass RLS.

- [ ] **Step 2: Write failing queue and audit tests**

Pin status/activity/group/date filters, separate public/internal reasons, no internal note in parent responses, staff source, mandatory override reason and redacted child/contact metadata.

- [ ] **Step 3: Run and confirm failure**

Run: `docker compose run --rm --no-deps api php artisan test tests/Feature/Enrollment/EnrollmentTenantIsolationTest.php tests/Feature/Enrollment/OrganizationEnrollmentQueueTest.php tests/Feature/Audit/AuditTrailTest.php`

Expected: FAIL because tenant policies/endpoints are absent.

- [ ] **Step 4: Enable RLS on organization-scoped tables**

Follow existing tenant session-variable policies. Keep child ownership outside broad tenant RLS; organization child access derives from a valid application/enrollment.

- [ ] **Step 5: Implement organization policy and controller**

Add `index`, `show`, `storeForParent`, `review`, `end`, `excludeSession`, `transfer`. Resolve through tenant-scoped queries and separate public/private presentation DTOs.

- [ ] **Step 6: Record safe audit events**

Record IDs/status/public reason only. Redact contact data, birth date and internal free text from generic audit metadata.

- [ ] **Step 7: Run focused tests**

Run the Step 3 command. Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add platform/apps/api/database/migrations/2026_10_28_000200_enable_enrollment_rls.php platform/apps/api/app/Http/Controllers/Api/V1/OrganizationEnrollmentController.php platform/apps/api/app/Domain/Enrollment/Policies/EnrollmentPolicy.php platform/apps/api/app/Domain/Audit/Enums/AuditAction.php platform/apps/api/routes/api.php platform/apps/api/tests/Feature/Enrollment platform/apps/api/tests/Feature/Audit/AuditTrailTest.php
git commit -m "feat: add tenant-safe enrollment queue"
```

## Task 6: Web API and Parent Enrollment Flow

**Files:**
- Create: `platform/apps/web/src/features/enrollment/api.ts`
- Create: `platform/apps/web/src/features/enrollment/enrollment-flow.tsx`
- Create: `platform/apps/web/src/features/enrollment/enrollment.module.css`
- Create: `platform/apps/web/src/app/enroll/[organizationSlug]/[activitySlug]/page.tsx`
- Modify: `platform/apps/web/src/features/catalog/public/api.ts`
- Modify: `platform/apps/web/src/features/catalog/public/activity-page.tsx`
- Test: `platform/apps/web/tests/{enrollment-api,enrollment-presentation}.test.ts`

**Interfaces:**
- Consumes: Tasks 2–3 parent endpoints and public group/session identifiers.
- Produces: typed child/application/summary types, `loadChildren`, `createChild`, `submitApplication`, `withdrawApplication`, and `<EnrollmentFlow />`.

- [ ] **Step 1: Write failing API contract tests**

Pin CSRF credentials, idempotency header, encoded paths, login return URL and Bulgarian 409/422 mapping without server-detail leakage.

- [ ] **Step 2: Write failing presentation tests**

Pin child → scope/date → terms/summary → result; exact no-automatic-prepayment explanation; trial/single date selection; waitlist language; disabled pending submit; no payment-success claim.

- [ ] **Step 3: Run and confirm failure**

Run: `docker compose run --rm --no-deps web pnpm test -- enrollment-api.test.ts enrollment-presentation.test.ts`

Expected: FAIL because feature files are absent.

- [ ] **Step 4: Implement typed API client**

Reuse `/backend` and CSRF patterns. Generate one idempotency key per intentional attempt and retain it across retry of that attempt.

- [ ] **Step 5: Implement responsive enrollment flow**

Use the catalog visual language, preserve return target through login, support inline child creation, show only eligible choices/sessions and distinguish submitted/approved/waitlisted outcomes.

- [ ] **Step 6: Replace placeholder catalog CTA**

Link to the enrollment route with organization/activity/group context while keeping the public page anonymous-readable.

- [ ] **Step 7: Run tests, typecheck and lint**

Run: `docker compose run --rm --no-deps web pnpm test -- enrollment-api.test.ts enrollment-presentation.test.ts && docker compose run --rm --no-deps web pnpm typecheck && docker compose run --rm --no-deps web pnpm lint`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add platform/apps/web/src/features/enrollment platform/apps/web/src/app/enroll platform/apps/web/src/features/catalog/public platform/apps/web/tests/enrollment-api.test.ts platform/apps/web/tests/enrollment-presentation.test.ts
git commit -m "feat: add parent enrollment flow"
```

## Task 7: Parent Dashboard and Organization Queue UI

**Files:**
- Create: `platform/apps/web/src/features/enrollment/parent-dashboard.tsx`
- Create: `platform/apps/web/src/features/enrollment/admin/enrollment-queue.tsx`
- Create: `platform/apps/web/src/app/parent/page.tsx`
- Create: `platform/apps/web/src/app/dashboard/enrollments/page.tsx`
- Modify: `platform/apps/web/src/features/enrollment/{api.ts,enrollment.module.css}`
- Test: `platform/apps/web/tests/{parent-dashboard-presentation,enrollment-admin-presentation}.test.ts`

**Interfaces:**
- Consumes: parent and organization endpoints from Tasks 3 and 5.
- Produces: `<ParentDashboard />` and `<EnrollmentQueue />`.

- [ ] **Step 1: Write failing parent-dashboard tests**

Pin children, active/queued/ended sections, applicable upcoming sessions, withdrawal and three cancellation scopes. State that financial effect is deferred and retain past sessions after future cancellation.

- [ ] **Step 2: Write failing admin-queue tests**

Pin counts, filters, capacity warnings, stale-version refresh, mandatory public rejection reason, separately labelled internal note, staff-created enrollment and transfer confirmation.

- [ ] **Step 3: Run and confirm failure**

Run: `docker compose run --rm --no-deps web pnpm test -- parent-dashboard-presentation.test.ts enrollment-admin-presentation.test.ts`

Expected: FAIL because components are absent.

- [ ] **Step 4: Implement parent dashboard**

Keep children and enrollment states in one workspace. Show cancellation scope before confirmation and refresh server state after success.

- [ ] **Step 5: Implement organization queue**

Use responsive list/detail. Send expected version; on 409 refresh the selected record and explain that another staff member changed it.

- [ ] **Step 6: Run tests, typecheck, lint and build**

Run: `docker compose run --rm --no-deps web pnpm test -- parent-dashboard-presentation.test.ts enrollment-admin-presentation.test.ts && docker compose run --rm --no-deps web pnpm typecheck && docker compose run --rm --no-deps web pnpm lint && docker compose run --rm --no-deps web pnpm build`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add platform/apps/web/src/features/enrollment platform/apps/web/src/app/parent platform/apps/web/src/app/dashboard/enrollments platform/apps/web/tests/parent-dashboard-presentation.test.ts platform/apps/web/tests/enrollment-admin-presentation.test.ts
git commit -m "feat: add enrollment workspaces"
```

## Task 8: Demo Data, End-to-End Verification and Documentation

**Files:**
- Modify: `platform/apps/api/database/seeders/LocalDemoSeeder.php`
- Modify: `platform/apps/api/tests/Feature/Demo/LocalDemoSeederTest.php`
- Create: `platform/apps/web/tests/e2e/enrollment.spec.ts`
- Modify: `platform/README.md`
- Modify: `platform/apps/api/CHANGELOG.md`

**Interfaces:**
- Consumes: Tasks 1–7.
- Produces: repeatable demo parent/children and representative submitted, waitlisted and active enrollment journeys.

- [ ] **Step 1: Write failing seed and browser tests**

Pin deterministic reruns. Browser journeys: public activity → login → select/create child → trial submission; parent withdrawal; admin approval; last-place waitlist; full-period join after past sessions; future cancellation; mobile-width use.

- [ ] **Step 2: Run and confirm failure**

Run: `docker compose run --rm --no-deps api php artisan test tests/Feature/Demo/LocalDemoSeederTest.php && docker compose run --rm --no-deps web pnpm test:e2e -- enrollment.spec.ts`

Expected: FAIL because demo enrollment data/flows are absent.

- [ ] **Step 3: Extend deterministic demo data**

Add one parent with two children and representative states without changing existing demo users/catalog.

- [ ] **Step 4: Document roles and boundaries**

Document how to view both flows and state that payments, delivered notifications and attendance are not implemented yet.

- [ ] **Step 5: Run complete verification**

Run these commands separately:

```bash
docker compose run --rm --no-deps api composer verify
docker compose run --rm --no-deps web pnpm test
docker compose run --rm --no-deps web pnpm typecheck
docker compose run --rm --no-deps web pnpm lint
docker compose run --rm --no-deps web pnpm build
docker compose run --rm --no-deps web pnpm test:e2e
docker compose up -d --force-recreate api web
```

Expected: every check passes; PostgreSQL, Redis and API are healthy; web is started.

- [ ] **Step 6: Verify in browser at desktop and mobile widths**

Confirm no console errors and approved language for CTA, login return, child creation, all scopes, submitted/waitlisted/approved states, admin decision, future cancellation and capacity refresh.

- [ ] **Step 7: Commit**

```bash
git add platform/apps/api/database/seeders/LocalDemoSeeder.php platform/apps/api/tests/Feature/Demo/LocalDemoSeederTest.php platform/apps/web/tests/e2e/enrollment.spec.ts platform/README.md platform/apps/api/CHANGELOG.md
git commit -m "test: verify parent enrollment journey"
```

## Final Review Gate

- [ ] Map every approved-spec requirement to Tasks 1–8.
- [ ] Confirm no payment, attendance, delivered-notification or global-marketplace feature is implied as working.
- [ ] Review child-data exposure, tenant bypass, stale writes and capacity races across the whole diff.
- [ ] Run `git diff --check` and keep unrelated user changes out of feature commits.
- [ ] Re-run Task 8 verification on the final tree.
