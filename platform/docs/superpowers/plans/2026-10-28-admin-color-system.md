# Administrative Color System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize and lock the approved administrative palette without changing dashboard behavior or organization-specific public branding.

**Architecture:** Dashboard-scoped CSS custom properties live on the shell root and are inherited by administrative pages. Existing literal palette values in the dashboard shell and overview page are replaced by semantic variables, while semantic status colors remain separate.

**Tech Stack:** Next.js 16, React, CSS Modules, Vitest

**Spec:** `docs/superpowers/specs/2026-10-28-admin-color-system-design.md`

## Global Constraints

- Keep the exact approved core hex values from the spec.
- Do not allow organization branding to override the administrative shell.
- Preserve responsive navigation and status semantics.
- Introduce no new dependency.

## Review Focus

- A future organization theme must not replace dashboard tokens.
- Disabled navigation must remain readable but clearly inactive.
- Focus and active states must retain visible contrast.
- Warning, success, and error colors must remain distinguishable from brand accents.
- Mobile and collapsed navigation must inherit the same palette as desktop.

---

### Task 1: Dashboard palette tokens

**Files:**
- Modify: `apps/web/src/features/dashboard/dashboard-shell.module.css`
- Modify: `apps/web/src/app/dashboard/page.module.css`
- Test: `apps/web/tests/dashboard-palette.test.ts`

**Interfaces:**
- Consumes: Dashboard shell root class and inherited CSS custom properties.
- Produces: Stable `--admin-*` CSS variables for administrative components.

- [ ] **Step 1: Write the failing palette contract test**

Assert that the shell declares every approved token with its exact hex value, that shell/overview rules consume semantic variables, and that no organization-derived inline style controls the palette.

- [ ] **Step 2: Run the focused test and confirm it fails**

Run: `../../node_modules/.pnpm/node_modules/.bin/vitest run tests/dashboard-palette.test.ts`

- [ ] **Step 3: Add the dashboard-scoped tokens and replace core literal colors**

Declare the core palette on `.shell`; consume it throughout the shell and dashboard overview. Keep status colors explicitly separate.

- [ ] **Step 4: Run focused and existing dashboard tests**

Run: `../../node_modules/.pnpm/node_modules/.bin/vitest run tests/dashboard-palette.test.ts tests/dashboard-navigation.test.ts`

- [ ] **Step 5: Run typecheck, lint, build, and responsive visual verification**

Verify the dashboard at desktop, tablet, and mobile widths with no behavior or overflow regression.

- [ ] **Step 6: Commit**

Commit only the palette contract, CSS changes, spec, and plan.

