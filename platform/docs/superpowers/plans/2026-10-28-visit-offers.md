# Visit Offers and Open Days Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add publishable trial, single-visit and open-day offers with trustworthy capacity, eligibility, pricing and public badges before building the parent-facing enrollment screens.

**Architecture:** Introduce organization-scoped visit offers and optional open-day campaigns in the Catalog domain, then connect them to the existing Enrollment application and capacity services. Admin APIs own offer configuration; public catalog APIs expose only published, currently actionable projections; the existing Next.js catalog renders the approved information hierarchy.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL/SQLite test database, PHPUnit 12, Next.js 16, React 19, TypeScript 5, Vitest, Playwright.

**Spec:** `platform/docs/superpowers/specs/2026-10-28-visit-offers-design.md`

## Global Constraints

- Overall session capacity is always the hard ceiling across full-period, trial and single enrollments.
- Trial/single limits never reserve empty seats against full-period enrollment.
- Money is stored as integer eurocents; a paid offer requires a public fee explanation.
- Trial default is one visit per child, activity and academic year; single default is two visits per rolling 30 days.
- Availability badges are computed, never manually assigned: last place at 1; limited at at most 25% or at most 3 places; closed enrollment overrides remaining capacity.
- Public cards show at most two status badges and a separate audience line for age/class.
- Existing confirmed applications retain price, fee reason and terms snapshots after an offer is edited.
- All new organization-owned records use tenant scoping, PostgreSQL RLS and audit history.
- Do not add payment capture, automatic refunds, invoices or automated penalty charges in this plan.

## Review Focus

- A session whose explicit capacity is lower than the group capacity must use the session ceiling and never overbook.
- Offer limits of zero, stale sessions, DST/timezone boundaries and exactly-at-deadline submissions must fail predictably.
- Editing or cancelling an offer after applications exist must preserve snapshots and history without silently changing confirmed visits.
- A child with visits through another organization must neither be blocked incorrectly nor expose cross-tenant data.
- Small groups must produce sensible availability badges when both the percentage and absolute thresholds apply.

---

### Task 1: Visit-offer persistence and tenant isolation

**Files:**
- Create: `platform/apps/api/database/migrations/2026_10_28_000200_create_visit_offer_tables.php`
- Create: `platform/apps/api/app/Domain/Catalog/Enums/VisitOfferType.php`
- Create: `platform/apps/api/app/Domain/Catalog/Enums/VisitOfferStatus.php`
- Create: `platform/apps/api/app/Domain/Catalog/Enums/VisitFeeType.php`
- Create: `platform/apps/api/app/Domain/Catalog/Models/VisitOffer.php`
- Create: `platform/apps/api/app/Domain/Catalog/Models/OpenDayCampaign.php`
- Modify: `platform/apps/api/app/Domain/Catalog/Models/Activity.php`
- Modify: `platform/apps/api/app/Domain/Catalog/Models/Group.php`
- Modify: `platform/apps/api/app/Domain/Catalog/Models/Session.php`
- Modify: `platform/apps/api/app/Domain/Enrollment/Models/EnrollmentApplication.php`
- Test: `platform/apps/api/tests/Feature/Catalog/VisitOfferSchemaTest.php`
- Test: `platform/apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php`

**Interfaces:**
- Consumes: Existing `BelongsToCatalogTenant`, organization context, catalog activities/groups/sessions and enrollment applications.
- Produces: `VisitOffer`, `OpenDayCampaign`, their enums and relations; nullable `visit_offer_id`, `fee_reason_snapshot`, `cancellation_policy_snapshot` on applications.

- [ ] **Step 1: Write failing schema and model tests**

Assert valid enum values, organization-scoped relations, campaign membership, offer-to-session membership, positive limits, non-negative eurocents, paid-fee explanation, snapshot columns, and safe defaults. Add tenant-isolation assertions that foreign organizations cannot list or mutate offers/campaigns.

- [ ] **Step 2: Run the focused tests and confirm failure**

Run: `docker compose run --rm --build api php artisan test tests/Feature/Catalog/VisitOfferSchemaTest.php tests/Feature/Catalog/CatalogTenantIsolationTest.php`

Expected: FAIL because visit-offer schema and models do not exist.

- [ ] **Step 3: Add the migration, enums, models and relations**

Use tables `visit_offers`, `visit_offer_sessions`, and `open_day_campaigns`; key offers by organization, activity and group; attach them to at most one optional campaign with nullable `campaign_id`. Campaigns store `slug`, title, description, start/end dates, image and status. Offers store `type`, `status`, `title`, `description`, audience labels, `visit_limit`, `fee_type`, `amount_cents`, `currency`, `fee_reason`, booking/cancellation deadlines, cancellation outcome, terms version, eligibility settings, publication timestamps and optimistic `version`. Add CHECK/trigger guards and PostgreSQL RLS consistent with existing catalog tables.

- [ ] **Step 4: Run focused tests and all enrollment schema tests**

Run: `docker compose run --rm --build api php artisan test tests/Feature/Catalog/VisitOfferSchemaTest.php tests/Feature/Catalog/CatalogTenantIsolationTest.php tests/Feature/Enrollment/EnrollmentSchemaTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add platform/apps/api/database/migrations/2026_10_28_000200_create_visit_offer_tables.php platform/apps/api/app/Domain/Catalog platform/apps/api/app/Domain/Enrollment/Models/EnrollmentApplication.php platform/apps/api/tests/Feature/Catalog
git commit -m "feat(catalog): add visit offer persistence"
```

### Task 2: Capacity, availability and eligibility rules

**Files:**
- Create: `platform/apps/api/app/Domain/Catalog/ValueObjects/VisitOfferAvailability.php`
- Create: `platform/apps/api/app/Domain/Catalog/Services/VisitOfferEligibility.php`
- Create: `platform/apps/api/app/Domain/Catalog/Services/VisitOfferAvailabilityService.php`
- Modify: `platform/apps/api/app/Domain/Enrollment/Services/EnrollmentCapacity.php`
- Test: `platform/apps/api/tests/Unit/Catalog/VisitOfferEligibilityTest.php`
- Test: `platform/apps/api/tests/Unit/Catalog/VisitOfferAvailabilityTest.php`
- Test: `platform/apps/api/tests/Feature/Enrollment/VisitOfferCapacityTest.php`

**Interfaces:**
- Consumes: `VisitOffer`, `Child`, `Session`, existing enrollments/applications and `SessionApplicability`.
- Produces: `VisitOfferEligibility::assertEligible(Child $child, VisitOffer $offer, Session $session, ?User $overrideActor = null): void`; `VisitOfferAvailabilityService::for(VisitOffer $offer, Session $session, CarbonImmutable $at): VisitOfferAvailability`; `EnrollmentCapacity::remainingForSession(Session $session): int` and `remainingForOffer(VisitOffer $offer, Session $session): int`.

- [ ] **Step 1: Write failing rule tests**

Pin the hard session ceiling, non-reserving offer limits, last-place concurrency, zero limit rejection, lower session capacity, closed deadlines, cancelled sessions, one trial per activity/academic year, two single visits per rolling 30 days, organization overrides, reasoned staff exception and cross-tenant isolation.

- [ ] **Step 2: Run the focused tests and confirm failure**

Run: `docker compose run --rm --build api php artisan test tests/Unit/Catalog/VisitOfferEligibilityTest.php tests/Unit/Catalog/VisitOfferAvailabilityTest.php tests/Feature/Enrollment/VisitOfferCapacityTest.php`

Expected: FAIL because the services do not exist.

- [ ] **Step 3: Implement the services and immutable availability value**

Return status values `available`, `limited`, `last_place`, `full`, or `closed`, remaining total/offer places, and `waitlist_available`. Use the more urgent result when absolute and percentage thresholds overlap; evaluate deadlines in the session/organization timezone; lock the session and relevant applications/enrollments before allocating the last place.

- [ ] **Step 4: Run focused tests**

Run: `docker compose run --rm --build api php artisan test tests/Unit/Catalog/VisitOfferEligibilityTest.php tests/Unit/Catalog/VisitOfferAvailabilityTest.php tests/Feature/Enrollment/VisitOfferCapacityTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add platform/apps/api/app/Domain/Catalog/Services platform/apps/api/app/Domain/Catalog/ValueObjects platform/apps/api/app/Domain/Enrollment/Services/EnrollmentCapacity.php platform/apps/api/tests/Unit/Catalog platform/apps/api/tests/Feature/Enrollment/VisitOfferCapacityTest.php
git commit -m "feat(enrollment): enforce visit offer capacity and eligibility"
```

### Task 3: Organization administration API

**Files:**
- Create: `platform/apps/api/app/Domain/Catalog/Actions/SaveVisitOffer.php`
- Create: `platform/apps/api/app/Domain/Catalog/Actions/PublishVisitOffer.php`
- Create: `platform/apps/api/app/Domain/Catalog/Actions/SaveOpenDayCampaign.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/VisitOfferController.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/OpenDayCampaignController.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Catalog/VisitOfferAdminTest.php`

**Interfaces:**
- Consumes: Task 1 models/enums and Task 2 availability validation.
- Produces: tenant routes under `/api/v1/catalog/visit-offers` and `/api/v1/catalog/open-day-campaigns`; create, list, show, update, publish, close and cancel operations with optimistic versioning.

- [ ] **Step 1: Write failing admin API tests**

Cover role authorization, draft creation, exact eurocent validation, paid reason requirement, session/group/activity consistency, default 12-hour booking and 24-hour cancellation deadlines, campaign composition, optimistic conflict, publication validation, cancellation history and non-destructive campaign removal.

- [ ] **Step 2: Run the focused test and confirm failure**

Run: `docker compose run --rm --build api php artisan test tests/Feature/Catalog/VisitOfferAdminTest.php`

Expected: FAIL with missing routes.

- [ ] **Step 3: Implement actions, controllers and routes**

Keep request validation/presentation in controllers and state transitions in actions. Record audit events for publication, closure, cancellation, capacity, price, deadline and policy changes. Reject incompatible changes to offers with confirmed applications unless the action explicitly preserves existing snapshots.

- [ ] **Step 4: Run admin and authorization tests**

Run: `docker compose run --rm --build api php artisan test tests/Feature/Catalog/VisitOfferAdminTest.php tests/Feature/Organizations/AuthorizationMatrixTest.php tests/Feature/Audit/AuditTrailTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add platform/apps/api/app/Domain/Catalog/Actions platform/apps/api/app/Http/Controllers/Api/V1 platform/apps/api/routes/api.php platform/apps/api/tests/Feature/Catalog/VisitOfferAdminTest.php
git commit -m "feat(catalog): add visit offer administration api"
```

### Task 4: Parent application integration and visit outcomes

**Files:**
- Modify: `platform/apps/api/app/Domain/Enrollment/Actions/SubmitEnrollmentApplication.php`
- Create: `platform/apps/api/app/Domain/Enrollment/Actions/RecordVisitOutcome.php`
- Modify: `platform/apps/api/app/Http/Controllers/Api/V1/ParentEnrollmentController.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Enrollment/ParentVisitOfferApplicationTest.php`
- Test: `platform/apps/api/tests/Feature/Enrollment/VisitOutcomeTest.php`

**Interfaces:**
- Consumes: `VisitOfferEligibility::assertEligible`, `EnrollmentCapacity::remainingForOffer`, Task 1 snapshot fields and existing idempotent application submission.
- Produces: parent submission accepting `visit_offer_id` and `session_id`; `RecordVisitOutcome::handle(User $actor, Enrollment $enrollment, string $outcome, ?string $reason): EnrollmentTransition` for `attended`, `cancelled_in_time`, `late_cancelled`, or `no_show`.

- [ ] **Step 1: Write failing application and outcome tests**

Assert published offer selection, selected-session membership, capacity/waitlist behavior, exact snapshots, repeated idempotency, deadline boundary, timely cancellation releasing a place, no-show counting as used, two-no-show restriction and reasoned staff override. Confirm another tenant's historical visits do not leak or block incorrectly.

- [ ] **Step 2: Run the focused tests and confirm failure**

Run: `docker compose run --rm --build api php artisan test tests/Feature/Enrollment/ParentVisitOfferApplicationTest.php tests/Feature/Enrollment/VisitOutcomeTest.php`

Expected: FAIL because visit offers are not accepted by submission.

- [ ] **Step 3: Integrate submission and outcome recording**

For `trial` and `single`, require `visit_offer_id` and one selected allowed session; derive scope, organization, group and price snapshots server-side. Preserve existing full-period behavior without requiring an offer. Store outcome metadata in immutable transitions/events and never perform a refund.

- [ ] **Step 4: Run all enrollment tests**

Run: `docker compose run --rm --build api php artisan test tests/Feature/Enrollment tests/Unit/Enrollment`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add platform/apps/api/app/Domain/Enrollment platform/apps/api/app/Http/Controllers/Api/V1/ParentEnrollmentController.php platform/apps/api/routes/api.php platform/apps/api/tests/Feature/Enrollment
git commit -m "feat(enrollment): submit and track visit offers"
```

### Task 5: Public catalog and campaign projections

**Files:**
- Create: `platform/apps/api/app/Domain/Catalog/Services/PublicAvailabilityPresenter.php`
- Modify: `platform/apps/api/app/Http/Controllers/Api/V1/PublicCatalogController.php`
- Modify: `platform/apps/api/routes/api.php`
- Test: `platform/apps/api/tests/Feature/Catalog/PublicVisitOfferTest.php`
- Modify: `platform/apps/api/tests/Feature/Catalog/PublicCatalogTest.php`

**Interfaces:**
- Consumes: Task 2 availability service and published visit offers/campaigns.
- Produces: public activity/card fields `audience`, computed `availability`, and at most one `special_offer`; activity `visit_offers`; `/public/organizations/{organizationSlug}/open-days/{campaignSlug}` campaign projection.

- [ ] **Step 1: Write failing public projection tests**

Cover all five availability statuses, small-group threshold overlap, audience age-only fallback, maximum two badges, published/current filtering, campaign grouping by day/activity, mixed free/paid offers, full offer visibility with waitlist action, stale/cancelled offer exclusion and no private/internal fields.

- [ ] **Step 2: Run public tests and confirm failure**

Run: `docker compose run --rm --build api php artisan test tests/Feature/Catalog/PublicVisitOfferTest.php tests/Feature/Catalog/PublicCatalogTest.php`

Expected: FAIL with missing fields and campaign route.

- [ ] **Step 3: Implement public projections and route**

Keep badge priority `closed > full > last_place > limited > available`; expose one special label from `open_day`, `trial`, or `single`; expose public fee reason, cancellation summary and booking deadline only on detail projections; never expose internal notes or override history.

- [ ] **Step 4: Run public and tenant tests**

Run: `docker compose run --rm --build api php artisan test tests/Feature/Catalog/PublicVisitOfferTest.php tests/Feature/Catalog/PublicCatalogTest.php tests/Feature/Catalog/CatalogTenantIsolationTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add platform/apps/api/app/Domain/Catalog/Services/PublicAvailabilityPresenter.php platform/apps/api/app/Http/Controllers/Api/V1/PublicCatalogController.php platform/apps/api/routes/api.php platform/apps/api/tests/Feature/Catalog
git commit -m "feat(catalog): expose public visit offers and badges"
```

### Task 6: Administrative visit-offer interface

**Files:**
- Create: `platform/apps/web/src/features/catalog/admin/visit-offer-editor.tsx`
- Create: `platform/apps/web/src/features/catalog/admin/visit-offer-editor.module.css`
- Create: `platform/apps/web/src/app/dashboard/activities/[activityId]/visit-offers/page.tsx`
- Modify: `platform/apps/web/src/features/catalog/admin/api.ts`
- Modify: `platform/apps/web/src/features/catalog/admin/presentation.ts`
- Modify: `platform/apps/web/src/features/catalog/admin/activity-editor.tsx`
- Test: `platform/apps/web/tests/visit-offer-admin-presentation.test.ts`

**Interfaces:**
- Consumes: Task 3 admin endpoints and existing catalog workspace/group/session types.
- Produces: six-step Bulgarian editor for type/content, activity/session, limit/eligibility, price/reason, deadlines/policy and preview/publish.

- [ ] **Step 1: Write failing UI behavior tests**

Assert required paid explanation, exact two-decimal conversion to cents, default deadlines, type-specific eligibility defaults, session selection, preservation of entered sections during navigation, optimistic version submission, inline optional-field examples and publish-blocking errors.

- [ ] **Step 2: Run the focused web test and confirm failure**

Run: `docker compose run --rm --build web pnpm test -- visit-offer-admin-presentation.test.ts`

Expected: FAIL because the editor does not exist.

- [ ] **Step 3: Implement types, API client, editor and route**

Use the existing admin design language. Keep optional copy guidance visible and prepare a disabled/feature-gated „Генерирай с AI“ action without pretending generation works. Link the editor from the existing activity editor without changing the unrelated login page.

- [ ] **Step 4: Run web tests, typecheck and lint**

Run: `docker compose run --rm --build web pnpm test -- visit-offer-admin-presentation.test.ts && docker compose run --rm web pnpm typecheck && docker compose run --rm web pnpm lint`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add platform/apps/web/src/features/catalog/admin platform/apps/web/src/app/dashboard/activities
git commit -m "feat(web): add visit offer administration"
```

### Task 7: Public cards, activity offers and open-day page

**Design addendum:** `platform/docs/superpowers/specs/2026-10-28-public-activity-premium-design.md`

**Files:**
- Modify: `platform/apps/web/src/features/catalog/public/api.ts`
- Modify: `platform/apps/web/src/features/catalog/public/presentation.ts`
- Modify: `platform/apps/web/src/features/catalog/public/catalog-page.tsx`
- Modify: `platform/apps/web/src/features/catalog/public/activity-page.tsx`
- Modify: `platform/apps/web/src/features/catalog/public/catalog.module.css`
- Create: `platform/apps/web/src/features/catalog/public/category-illustration.tsx`
- Create: `platform/apps/web/src/features/catalog/public/open-day-page.tsx`
- Create: `platform/apps/web/src/app/o/[organizationSlug]/open-days/[campaignSlug]/page.tsx`
- Create: `platform/apps/web/tests/visit-offer-public-presentation.test.ts`
- Create: `platform/apps/web/tests/e2e/public-visit-offers.spec.ts`

**Interfaces:**
- Consumes: Task 5 public projections.
- Produces: approved card hierarchy, deterministic category artwork, server-derived availability/special badges, age/class audience row, adaptive rich/balanced/minimal activity layouts, an honest offer chooser and a public campaign page grouped by local date/activity.

- [ ] **Step 1: Write failing presentation and browser tests**

In `visit-offer-public-presentation.test.ts`, pin Bulgarian labels for every server availability/action value; maximum one availability plus one special-offer badge; audience outside the badge row with `6–8 г. · I–II клас` and age-only fallback; `Безплатно`, exact and `От ...` prices; trimmed-content filtering; and closed actions without `href`. Assert that multiple offers produce a chooser target and never silently select the first session.

In `public-visit-offers.spec.ts`, add rich, balanced and minimal fixtures. Cover missing and failed real images with deterministic category artwork; whitespace-only optional sections with no empty heading/landmark/gap; physical navigation only when the API supplies its safe action; no invented campaign location; full/waitlist behavior only from the server; inactive campaign 404 with a friendly unavailable state; and local-day/activity grouping with mixed free and paid campaign offers.

- [ ] **Step 2: Run focused tests and confirm failure**

Run: `docker compose run --rm --build web pnpm test -- visit-offer-public-presentation.test.ts`

Expected: FAIL because adaptive presentation helpers and category artwork do not exist.

Run: `docker compose run --rm web pnpm test:e2e -- public-visit-offers.spec.ts`

Expected: FAIL because the projections are not rendered.

- [ ] **Step 3: Implement deterministic presentation helpers and category artwork**

Filter optional strings with `trim()` and arrays to meaningful entries before rendering. Map the API price label/from semantics without recomputing money or availability. Implement `CategoryIllustration` as a code-native SVG/CSS fallback for chess, sport, arts, languages, technology, music and general education; keep its geometry identical to real media and use safe organization accent tokens. A decorative fallback has empty alt text; a real image describes the activity and falls back without layout shift when loading fails.

- [ ] **Step 4: Implement the adaptive catalog and activity page**

Render image/artwork, at most two status badges, title, separate audience row, optional clamped summary, server price, schedule and bottom-aligned action on each card. Build rich, balanced and minimal activity compositions which completely omit unavailable optional sections. Keep price, availability, group/session schedule, public location and honest next action prominent; a physical location may render the existing safe navigation action, while online/private/missing locations never do.

When one offer exists, show its details and server-permitted state. When several exist, scroll to an accessible chooser and require an explicit selection. Until the parent enrollment flow is built, do not simulate booking success, login return or a working sticky enrollment CTA. Full/closed offers remain informative; waitlist is actionable only when the projection allows it.

- [ ] **Step 5: Implement the open-day page and route**

Render the campaign title, meaningful description, wrapper period and published image/category fallback. Group offers by their actual local calendar day and activity while preserving each offer's own time, price, remaining state and action. Do not infer campaign-wide capacity or location. Convert the public 404 for an inactive/empty campaign into a friendly Bulgarian unavailable page.

- [ ] **Step 6: Complete responsive and accessibility styling**

Verify the premium navy/orange/green/cream system at 390, 768, 820, 1024 and 1440 px. Use one-column phone layouts, two-column tablet cards with an odd final card spanning the row, and a maximum content width near 1180 px on desktop. Prevent page-level horizontal overflow; preserve only a named keyboard-scrollable related carousel if real API content exists. Provide visible focus, semantic headings, text-backed statuses, 44×44 px targets, reduced-motion support and safe-area spacing. Do not add invented partner, thematic-plan or related-activity content.

- [ ] **Step 7: Run focused tests and visual QA**

Run: `docker compose run --rm --build web pnpm test -- visit-offer-public-presentation.test.ts && docker compose run --rm web pnpm test:e2e -- public-visit-offers.spec.ts`

Expected: PASS.

Capture rich, balanced and minimal pages at 390, 768, 820, 1024 and 1440 px. Confirm no page overflow, no empty optional section, stable media geometry, usable keyboard focus and no control below 44×44 px.

- [ ] **Step 8: Run the complete verification suite**

Run: `docker compose run --rm --build api composer verify`

Expected: PHP style, static analysis and all API tests PASS.

Run: `docker compose run --rm --build web pnpm test && docker compose run --rm web pnpm typecheck && docker compose run --rm web pnpm lint && docker compose run --rm web pnpm build`

Expected: all web tests, typecheck, lint and production build PASS.

- [ ] **Step 9: Commit**

```bash
git add platform/apps/web/src/features/catalog/public platform/apps/web/src/app/o platform/apps/web/tests/visit-offer-public-presentation.test.ts platform/apps/web/tests/e2e/public-visit-offers.spec.ts
git commit -m "feat(web): present visit offers and open days"
```
