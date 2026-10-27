# Organization Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Да се изгради invite-only кандидатстване на организации с безопасно операторско одобрение, ясни статуси и одитирани решения.

**Architecture:** Жизненият цикъл остава в Organizations домейна и се управлява само чрез две application actions: едното за кандидатската страна, другото за операторската. Отделна append-only review history пази вътрешните бележки, а централен status gate отделя onboarding, normal, restricted и suspended достъпа. Next.js предоставя два различни изгледа върху същото versioned API: карта в tenant таблото и отделна операторска конзола.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL/SQLite tests, TypeScript, Next.js 16, React 19, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-27-organization-onboarding-design.md`

## Global Constraints

- Пилотът е invite-only; публична регистрация не се добавя.
- Съществуващите организации се мигрират като `active` с `review_version = 1`.
- Само global `platform_admin`, който няма активно членство в целевата организация, може да извършва review преходи.
- Applicant API никога не връща internal notes, reviewer identity или чужди tenant данни.
- Всяка мутация използва optimistic locking чрез точен `review_version`; stale версия връща HTTP 409 и текущото безопасно състояние.
- State change и съответният audit event са в една database transaction.
- Development и staging използват само синтетични данни.
- Българският е основен език и основните интерфейси покриват WCAG 2.2 AA.
- Съществуващата потребителска редакция в login екрана не се променя или включва в commit.

## Review Focus

1. Platform admin с членство в целевата организация трябва да получи 403 при review, независимо от активния UI контекст — покрива се в Task 3.
2. Две review заявки с една версия не трябва да презаписват решение или да създават втори audit event — покрива се в Task 3.
3. Applicant response не трябва да съдържа internal note или reviewer identity — покрива се в Task 2.
4. `restricted` и `suspended` не трябва да получават нормален mutation достъп чрез заобикаляне на UI — покрива се в Task 1.
5. Повторен submit в `review` е idempotent, но submit от всеки друг невалиден статус връща 409 — покрива се в Task 2.

---

### Task 1: Lifecycle schema, status gate and review history

**Files:**
- Create: `platform/apps/api/app/Domain/Organizations/Enums/OrganizationStatus.php`
- Create: `platform/apps/api/app/Domain/Organizations/Enums/OrganizationAccessMode.php`
- Create: `platform/apps/api/app/Domain/Organizations/Models/OrganizationReview.php`
- Create: `platform/apps/api/app/Http/Middleware/RequireOrganizationAccess.php`
- Create: `platform/apps/api/app/Domain/Organizations/Tenancy/OperatorContext.php`
- Create: `platform/apps/api/database/migrations/2026_10_27_000700_add_onboarding_fields_to_organizations.php`
- Create: `platform/apps/api/database/migrations/2026_10_27_000800_create_organization_reviews_table.php`
- Create: `platform/apps/api/tests/Feature/Organizations/OrganizationStatusGateTest.php`
- Modify: `platform/apps/api/app/Domain/Organizations/Models/Organization.php`
- Modify: `platform/apps/api/app/Http/Middleware/ResolveTenant.php`
- Modify: `platform/apps/api/bootstrap/app.php`

**Interfaces:**
- Produces: `OrganizationStatus::accessMode(): OrganizationAccessMode`, `OrganizationStatus::canTransitionTo(OrganizationStatus): bool`, parameterized middleware `organization.access:<mode>`; request-scoped `OperatorContext` remains inactive by default.
- Consumes: verified `TenantContext` from Task 4 of the foundation plan.

- [ ] **Step 1: Write the failing lifecycle and access-gate tests**

Test all allowed transitions from the spec and reject every unspecified transition. Assert `applicant/review/approved` allow only onboarding, `active` allows all three modes, `restricted` allows onboarding plus operational read, and `suspended` allows onboarding only. Assert direct write request denial, not merely hidden UI.

- [ ] **Step 2: Run the tests and verify RED**

Run: `cd platform && docker compose run --rm --no-deps api php artisan test tests/Feature/Organizations/OrganizationStatusGateTest.php`

Expected: FAIL because lifecycle enums, schema and middleware do not exist.

- [ ] **Step 3: Implement the schema, enums and status middleware**

Backfill existing organizations to `active`, version `1`; cast status/timestamps on `Organization`; make review rows append-only at the model and database layer. Keep `ResolveTenant` responsible for membership and tenant selection, while the new middleware owns status capability checks.

- [ ] **Step 4: Run lifecycle and existing tenant suites**

Run: `cd platform && docker compose run --rm --no-deps api php artisan test tests/Feature/Organizations/OrganizationStatusGateTest.php tests/Feature/Organizations/TenantIsolationTest.php`

Expected: PASS with no tenant isolation regressions.

- [ ] **Step 5: Commit**

`git commit -m "feat: add organization lifecycle status gate"`

### Task 2: Applicant draft and submission flow

**Files:**
- Create: `platform/apps/api/app/Domain/Organizations/Actions/SubmitOrganizationApplication.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/OrganizationApplicationController.php`
- Create: `platform/apps/api/tests/Feature/Organizations/OrganizationOnboardingTest.php`
- Modify: `platform/apps/api/app/Domain/Audit/Enums/AuditAction.php`
- Modify: `platform/apps/api/app/Domain/Organizations/Models/Organization.php`
- Modify: `platform/apps/api/app/Domain/Organizations/Policies/OrganizationPolicy.php`
- Modify: `platform/apps/api/routes/api.php`

**Interfaces:**
- Consumes: `OrganizationStatus`, `TenantContext`, `AuditRecorder` and onboarding status middleware.
- Produces: `GET/PATCH /api/v1/organization-application`, `POST /api/v1/organization-application/submit`, safe presenter `OrganizationApplicationController::presentApplicantState(Organization): array`.

- [ ] **Step 1: Write failing applicant API tests**

Cover safe read, draft update, required fields, stale update, owner-only submit, idempotent repeated submit, invalid-status submit, cross-tenant denial, public reason/next action visibility and explicit absence of internal notes/reviewer fields.

- [ ] **Step 2: Run the tests and verify RED**

Run: `cd platform && docker compose run --rm --no-deps api php artisan test tests/Feature/Organizations/OrganizationOnboardingTest.php --filter=applicant`

Expected: FAIL with missing routes/actions.

- [ ] **Step 3: Implement draft update and atomic submit**

Use `UPDATE ... WHERE review_version = ?` semantics for optimistic locking. Submit changes `applicant` to `review`, increments the version once and records `organization.application_submitted` with only previous/new status and version.

- [ ] **Step 4: Run applicant and audit suites**

Run: `cd platform && docker compose run --rm --no-deps api php artisan test tests/Feature/Organizations/OrganizationOnboardingTest.php tests/Feature/Audit/AuditTrailTest.php`

Expected: PASS; audit payload tests remain clean.

- [ ] **Step 5: Commit**

`git commit -m "feat: add organization application submission"`

### Task 3: Operator bootstrap, queue and review decisions

**Files:**
- Create: `platform/apps/api/app/Domain/Organizations/Actions/BootstrapOrganizationApplicant.php`
- Create: `platform/apps/api/app/Domain/Organizations/Actions/ReviewOrganizationApplication.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/OperatorOrganizationApplicationController.php`
- Create: `platform/apps/api/app/Http/Middleware/ResolvePlatformOperator.php`
- Create: `platform/apps/api/database/migrations/2026_10_27_000900_enable_operator_rls.php`
- Modify: `platform/apps/api/app/Domain/Audit/Enums/AuditAction.php`
- Modify: `platform/apps/api/app/Domain/Organizations/Enums/OrganizationRole.php`
- Modify: `platform/apps/api/routes/api.php`
- Modify: `platform/apps/api/tests/Feature/Organizations/OrganizationOnboardingTest.php`

**Interfaces:**
- Consumes: lifecycle/status gate, existing invitation hashing/expiry conventions and `AuditRecorder`.
- Produces: `POST/GET /api/v1/operator/organization-applications`, `POST /api/v1/operator/organization-applications/{organization}/review`; initial-owner one-time token; append-only `OrganizationReview` rows; middleware alias `platform.operator`.

- [ ] **Step 1: Write failing operator tests**

Cover platform-admin queue, applicant bootstrap, initial owner invitation, non-operator denial, member self-review denial, every allowed/invalid transition, per-transition reason validation, stale concurrent review, internal-note privacy and exactly one audit/review row per successful decision.

- [ ] **Step 2: Run the tests and verify RED**

Run: `cd platform && docker compose run --rm --no-deps api php artisan test tests/Feature/Organizations/OrganizationOnboardingTest.php --filter=operator`

Expected: FAIL with missing operator routes/actions.

- [ ] **Step 3: Implement global operator authorization and bootstrap flow**

Resolve global `platform_admin` from an active membership with that role, without trusting tenant headers. Set a transaction-scoped verified operator user ID; PostgreSQL RLS policies permit cross-tenant organization/review reads and writes only while that verified context is present. Do not use a bypass-RLS application role. Bootstrap creates the applicant and initial owner invitation transactionally. Return the token only from the creation response and never from list/read endpoints.

- [ ] **Step 4: Implement atomic versioned review transitions**

Validate mandatory public copy by transition; create append-only internal review history; update organization with exact expected version; record the matching safe audit action in the same transaction.

- [ ] **Step 5: Run full onboarding and authorization suites**

Run: `cd platform && docker compose run --rm --no-deps api php artisan test tests/Feature/Organizations/OrganizationOnboardingTest.php tests/Feature/Organizations/AuthorizationMatrixTest.php tests/Feature/Audit/AuditTrailTest.php`

Expected: PASS with no authorization or audit regression.

- [ ] **Step 6: Commit**

`git commit -m "feat: add operator organization approval"`

### Task 4: Applicant card and operator console

**Files:**
- Create: `platform/apps/web/src/features/organizations/application/api.ts`
- Create: `platform/apps/web/src/features/organizations/application/status-card.tsx`
- Create: `platform/apps/web/src/features/organizations/application/status-card.module.css`
- Create: `platform/apps/web/src/features/organizations/operator/operator-console.tsx`
- Create: `platform/apps/web/src/features/organizations/operator/operator-console.module.css`
- Create: `platform/apps/web/src/app/operator/organizations/page.tsx`
- Create: `platform/apps/web/tests/organization-onboarding-api.test.ts`
- Create: `platform/apps/web/tests/e2e/organization-approval.spec.ts`
- Modify: `platform/apps/web/src/features/dashboard/api.ts`
- Modify: `platform/apps/web/src/features/dashboard/dashboard.tsx`
- Modify: `platform/apps/web/src/app/dashboard/page.module.css`

**Interfaces:**
- Consumes: applicant and operator endpoints from Tasks 2–3.
- Produces: accessible Bulgarian applicant status card and `/operator/organizations` review console.

- [ ] **Step 1: Write failing web API and browser tests**

Assert applicant status/reason/next action, expected-version submission, operator queue, stale-review refresh and successful approval. Browser flow checks headings, visible active role/tenant, keyboard focus, confirmation copy and status change.

- [ ] **Step 2: Run the tests and verify RED**

Run: `cd platform/apps/web && ./node_modules/.bin/vitest run tests/organization-onboarding-api.test.ts`

Expected: FAIL because the feature client and components do not exist.

- [ ] **Step 3: Implement applicant status card**

Add it to the current dashboard without mixing internal operator data into `DashboardData`. Use status text plus icon, not color alone, and display the server-provided public reason/next action.

- [ ] **Step 4: Implement the separate operator console**

Use the existing visual tokens but a distinct operator header. Only valid actions render; confirmation states the resulting status; stale responses refresh the selected application.

- [ ] **Step 5: Run web verification and browser flow**

Run: `cd platform/apps/web && ./node_modules/.bin/eslint . && ./node_modules/.bin/tsc --noEmit && ./node_modules/.bin/vitest run && ./node_modules/.bin/next build`

Run: `cd platform && PLAYWRIGHT_REUSE_EXISTING_SERVER=1 PLAYWRIGHT_CDP_ENDPOINT=http://127.0.0.1:9222 pnpm test:e2e --grep "organization approval"`

Expected: unit tests, build and organization approval browser flow PASS.

- [ ] **Step 6: Run the complete Task 7 verification**

Run: `cd platform && docker compose run --rm --no-deps api composer verify && pnpm verify`

Expected: all API and web checks PASS.

- [ ] **Step 7: Commit**

`git commit -m "feat: add organization onboarding interfaces"`
