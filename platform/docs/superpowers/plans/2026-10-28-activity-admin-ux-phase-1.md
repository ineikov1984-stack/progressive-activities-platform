# Activity Administration UX Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the confusing activity administration entry point with a grouped, collapsible product navigation, useful activity catalog, three-step creation wizard and an honest landing page for the hybrid website/integration offering without losing existing data.

**Architecture:** Add a reusable dashboard shell first, then introduce backward-compatible structured activity metadata, and finally rebuild the catalog/wizard on those contracts. Existing editor and public routes remain operational while Phase 2 replaces the editor.

**Tech Stack:** Next.js 16, React 19, TypeScript 5, CSS Modules, Laravel 13, PostgreSQL, PHPUnit, Vitest, Playwright.

**Spec:** `platform/docs/superpowers/specs/2026-10-28-activity-admin-ux-phase-1-design.md`

## Global Constraints

- Preserve all existing activities, media, groups, prices, offers and public URLs.
- Never create a location, instructor, group, price or session implicitly from the wizard.
- Inactive navigation items have no `href` or activation behavior; visible text never says “Предстои”.
- Navigation is grouped as Управление, Бизнес and Организация; desktop starts expanded, tablet starts compact and mobile uses an off-canvas drawer.
- Collapse preference is scoped to the current user and organization and never leaks between tenants.
- The website builder is an optional paid module; the public subdomain profile and embed entry points remain part of the base platform.
- Collapsed navigation retains accessible names and focus tooltips; mobile uses an off-canvas menu.
- “Готова за преглед” is derived completeness, never a persisted lifecycle status.
- Phase 1 supports archive and duplicate, not permanent deletion.
- Legacy category and age text is retained until an explicit structured edit.

## Review Focus

- Organization switching must not reuse another tenant’s locally remembered navigation or wizard draft; corrupt or unavailable stored values must safely fall back to expanded desktop navigation.
- Ambiguous legacy age/category values must remain visible and editable without destructive conversion.
- Duplicating a published activity must not copy publication, enrollments, offers, sessions or historical state.
- Archived activities must remain restorable and must not disappear from dependent history.
- Disabled future navigation and unavailable site/integration actions must be noninteractive for mouse, keyboard and assistive technology; cards must not claim a connection is active without real data.

---

### Task 1: Reusable dashboard navigation shell

**Files:**
- Create: `platform/apps/web/src/features/dashboard/navigation.ts`
- Create: `platform/apps/web/src/features/dashboard/dashboard-shell.tsx`
- Create: `platform/apps/web/src/features/dashboard/dashboard-shell.module.css`
- Create: `platform/apps/web/src/features/dashboard/dashboard-context.tsx`
- Create: `platform/apps/web/src/app/dashboard/layout.tsx`
- Modify: `platform/apps/web/src/features/dashboard/dashboard.tsx`
- Modify: `platform/apps/web/src/app/dashboard/page.tsx`
- Test: `platform/apps/web/tests/dashboard-navigation.test.ts`
- Test: `platform/apps/web/tests/e2e/dashboard-navigation.spec.ts`

**Interfaces:**
- Produces `DashboardNavItem`, `DashboardNavGroup`, `dashboardNavigation(pathname)`, `DashboardContextValue` and `DashboardShell` for all `/dashboard` pages.
- Navigation order is Управление: Начало, Дейности, Групи и графици, Записвания, Отворени врати; Бизнес: Финанси, Анализи, Сайт и интеграции; Организация: Места, Екип, Медия, Настройки.

- [ ] Write failing tests for group/item order, active state, inactive no-link behavior, first desktop expanded state, compact tablet state, collapse persistence scoped by user/organization, invalid stored-state fallback, tooltip/focus labels and mobile drawer.
- [ ] Run focused unit/browser tests and confirm the current dashboard-only sidebar fails them.
- [ ] Implement code-native icons from one stroke system, group labels, button-like rows, active/hover/focus/disabled states and collapse control; do not add a new icon package when the existing code-native system can supply the required symbols.
- [ ] Wrap the dashboard routes without changing their existing content or authorization behavior.
- [ ] Verify 390/768/1024/1440 widths, keyboard navigation, reduced motion and no page overflow.
- [ ] Commit: `feat(web): add collapsible dashboard navigation`

### Task 2: Structured activity metadata and safe lifecycle actions

**Files:**
- Create: `platform/apps/api/database/migrations/2026_10_28_000300_add_activity_audience_fields.php`
- Modify: `platform/apps/api/app/Domain/Catalog/Models/Activity.php`
- Modify: `platform/apps/api/app/Http/Controllers/Api/V1/ActivityController.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Catalog/ActivityAdministrationUxTest.php`

**Interfaces:**
- Produces optional `category_key`, `age_min`, `age_max`, `grade_levels`; archive/restore and duplicate actions; keeps legacy `category` and `age_label` in responses.

- [ ] Write failing migration/API tests for controlled categories, PK–XII grades, age bounds, non-destructive legacy retention, archive/restore and safe duplication.
- [ ] Confirm tests fail against the current free-text-only activity contract.
- [ ] Add nullable structured fields and tenant-safe validation; derive display labels without overwriting ambiguous legacy values.
- [ ] Implement duplicate as a new draft containing presentation content/media references only; exclude offerings, groups, sessions, offers, applications and publication state.
- [ ] Implement recoverable archive/restore while preserving historical relations and public fail-closed behavior.
- [ ] Run focused tests and full API verification.
- [ ] Commit: `feat(catalog): structure activity administration metadata`

### Task 3: Activity catalog presentation and quick preview

**Files:**
- Modify: `platform/apps/web/src/features/catalog/admin/api.ts`
- Create: `platform/apps/web/src/features/catalog/admin/activity-catalog-presentation.ts`
- Create: `platform/apps/web/src/features/catalog/admin/activity-card.tsx`
- Create: `platform/apps/web/src/features/catalog/admin/activity-quick-preview.tsx`
- Modify: `platform/apps/web/src/features/catalog/admin/activity-list.tsx`
- Modify: `platform/apps/web/src/features/catalog/admin/activity-editor.module.css`
- Test: `platform/apps/web/tests/activity-catalog-presentation.test.ts`
- Test: `platform/apps/web/tests/e2e/activity-catalog.spec.ts`

**Interfaces:**
- Consumes Task 2 activity metadata/actions and existing media/group/price data.
- Produces derived completeness checklist, stable filters/sorts, rich cards and accessible quick-preview drawer.

- [ ] Write failing tests for cover priority, category fallback art, lifecycle/completeness separation, factual missing-value labels, active-group definition, filters and stable sorting.
- [ ] Write failing browser journeys for search/filter, quick preview, edit, duplicate, archive/restore and action-menu keyboard access.
- [ ] Implement cards with cover, audience, price, group count, next session, missing-data guidance and last-updated data.
- [ ] Implement quick-preview drawer with focus trap, Escape close and focus return.
- [ ] Implement card/list switch, responsive filter bar and informative empty states.
- [ ] Run focused tests and visual QA at target widths.
- [ ] Commit: `feat(web): rebuild the activity catalog`

### Task 4: Three-step “Създай нова дейност” wizard

**Files:**
- Create: `platform/apps/web/src/features/catalog/admin/activity-create-wizard.tsx`
- Create: `platform/apps/web/src/features/catalog/admin/activity-create-wizard.module.css`
- Modify: `platform/apps/web/src/features/catalog/admin/activity-list.tsx`
- Modify: `platform/apps/web/src/features/catalog/admin/api.ts`
- Test: `platform/apps/web/tests/activity-create-wizard.test.ts`
- Test: `platform/apps/web/tests/e2e/activity-create-wizard.spec.ts`

**Interfaces:**
- Consumes Task 2 structured create contract.
- Produces one activity draft only and navigates to the existing editor with an explicit next-step message.

- [ ] Write failing tests for name/category, age/grade selection, summary/cover, back/forward preservation and no implicit related records.
- [ ] Add recoverable organization-scoped local draft with discard action and leave warning.
- [ ] Implement three clear steps, examples, validation, progress, `Създай и продължи` and deterministic media fallback.
- [ ] Verify completion in under two minutes from a loaded organization catalog and confirm zero related records are created.
- [ ] Run unit, browser, typecheck, lint and production build.
- [ ] Commit: `feat(web): guide creation of a new activity`

### Task 5: Organization settings landing and migration of identity controls

**Files:**
- Create: `platform/apps/web/src/app/dashboard/settings/page.tsx`
- Create: `platform/apps/web/src/features/organizations/settings/organization-settings.tsx`
- Create: `platform/apps/web/src/features/organizations/settings/organization-settings.module.css`
- Modify: `platform/apps/web/src/features/catalog/admin/activity-editor.tsx`
- Test: `platform/apps/web/tests/e2e/organization-settings.spec.ts`

**Interfaces:**
- Consumes existing catalog profile API; does not introduce a second organization profile.
- Produces a functional Settings destination for profile/contacts and visual identity, plus visible future setting categories.

- [ ] Write failing browser tests proving existing logo/accent/public intro load and save through Settings and no longer appear as per-activity configuration.
- [ ] Implement Settings landing cards and functional profile/identity form with existing optimistic version behavior.
- [ ] Keep future settings categories visible but noninteractive under the same navigation rule.
- [ ] Verify tenant switching, unsaved changes and public catalog continuity.
- [ ] Run complete API/web verification and responsive visual QA.
- [ ] Commit: `feat(web): centralize organization identity settings`

### Task 6: “Сайт и интеграции” landing page

**Files:**
- Create: `platform/apps/web/src/app/dashboard/site-integrations/page.tsx`
- Create: `platform/apps/web/src/features/integrations/site-integrations-landing.tsx`
- Create: `platform/apps/web/src/features/integrations/site-integrations-landing.module.css`
- Test: `platform/apps/web/tests/site-integrations-presentation.test.ts`
- Test: `platform/apps/web/tests/e2e/site-integrations.spec.ts`

**Interfaces:**
- Consumes the existing public organization/catalog profile URL and the Task 1 dashboard shell.
- Produces an honest overview of `public_profile`, `site_builder`, `embeds`, `connected_apps` and `api` without creating fake connection state or unimplemented links.

- [ ] Write failing presentation tests for the five module types, base-plan versus optional-module labeling, public profile URL derivation and disabled unavailable actions.
- [ ] Write a failing browser journey that opens „Сайт и интеграции“, reaches the real public profile and cannot activate unimplemented builder/integration actions.
- [ ] Implement the responsive landing page using existing organization/profile data; render unknown connection state as „Не е настроено“, never as active.
- [ ] Verify keyboard order, disabled semantics, 390/768/1024/1440 layouts and no horizontal overflow.
- [ ] Commit: `feat(web): introduce site and integration hub`

### Task 7: Whole-flow validation and release gate

**Files:**
- Modify: `platform/README.md`
- Test: all Phase 1 focused and regression suites.

- [ ] Run API verify, all web unit tests, typecheck, lint and production build.
- [ ] Run dashboard/catalog/wizard/settings Playwright journeys with keyboard and 390/768/1024/1440 visual checks.
- [ ] Confirm old activity URLs, public URLs and visit-offer administration remain operational.
- [ ] Independently review the complete feature against the approved UX design.
- [ ] Commit only documentation corrections, if any: `docs: document activity administration workflow`.
