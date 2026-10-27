# Local Demo Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a synthetic local demo account and a protected dashboard that exposes real organization, permission and invitation state.

**Architecture:** Laravel remains the source of truth and provides enriched organization and invitation-list responses. An environment-gated seeder creates one repeatable demo tenant. Next.js renders a client-side protected dashboard through the existing same-origin backend proxy.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Next.js 16, React 19, TypeScript, Vitest, Playwright, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-10-27-local-demo-workspace-design.md`

## Global Constraints

- All demo records are synthetic and local-only.
- The dashboard consumes real API responses; it does not contain mocked business state.
- Existing tenant and role authorization remains mandatory.
- Invitation list responses never expose token hashes or raw tokens.
- Preserve the user's uncommitted login heading change.

## Review Focus

- Re-running the seeder must not duplicate the user, organization or membership.
- A production environment must refuse demo seeding.
- An unauthenticated dashboard visitor must be returned to login.
- A user must not list invitations for another tenant.
- API/network failure must produce a usable Bulgarian error state instead of a blank dashboard.

---

### Task 1: Demo data and dashboard API

**Files:**
- Create: `platform/apps/api/database/seeders/LocalDemoSeeder.php`
- Modify: `platform/apps/api/database/seeders/DatabaseSeeder.php`
- Modify: `platform/apps/api/app/Http/Controllers/Api/V1/OrganizationController.php`
- Modify: `platform/apps/api/app/Http/Controllers/Api/V1/InvitationController.php`
- Modify: `platform/apps/api/app/Domain/Organizations/Enums/OrganizationRole.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Demo/LocalDemoSeederTest.php`
- Test: `platform/apps/api/tests/Feature/Organizations/DashboardDataTest.php`

**Interfaces:**
- Produces: organization payload `{id,name,slug,status,role,permissions}` and invitation list payload without token fields.

- [ ] Write failing tests for idempotent/local-only seeding, role/permission organization data, same-tenant invitation listing and token redaction.
- [ ] Run the focused tests and confirm they fail for missing behavior.
- [ ] Implement the minimal seeder and API extensions.
- [ ] Run the focused tests and the complete API verification suite.
- [ ] Commit as `feat: add local demo workspace data`.

### Task 2: Protected administration dashboard

**Files:**
- Create: `platform/apps/web/src/app/dashboard/page.tsx`
- Create: `platform/apps/web/src/app/dashboard/page.module.css`
- Create: `platform/apps/web/src/features/dashboard/api.ts`
- Create: `platform/apps/web/src/features/dashboard/dashboard.tsx`
- Modify: `platform/apps/web/src/features/auth/login-form.tsx`
- Test: `platform/apps/web/tests/dashboard-api.test.ts`
- Test: `platform/apps/web/tests/e2e/dashboard.spec.ts`

**Interfaces:**
- Consumes: authenticated session and Task 1 organization/invitation payloads.
- Produces: `/dashboard` administration view and invitation form.

- [ ] Write failing API-client and browser tests for authenticated dashboard state, login redirect, invitation creation and Bulgarian network errors.
- [ ] Run the focused tests and confirm they fail for missing behavior.
- [ ] Implement the dashboard and change successful login redirect to `/dashboard`.
- [ ] Run web lint, typecheck, unit, build and browser tests.
- [ ] Start the refreshed local stack, seed demo data and verify the login flow in the browser.
- [ ] Commit as `feat: add local administration dashboard`.
