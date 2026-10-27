# Local Demo Workspace Design

## Intent

Give the product owner a safe, persistent way to log into the local platform and observe real capabilities as each implementation task lands.

## Goals

- Create one idempotent, synthetic local demo owner and organization.
- Redirect successful login to a protected Bulgarian administration dashboard.
- Show the authenticated user, active organization, role, effective permissions and real invitation records.
- Allow the demo owner to create an invitation through the real API.
- Keep the dashboard ready to add Task 6 audit events without redesigning its shell.

## Non-goals

- This is not the final production administration design.
- It does not add public registration, real email delivery or real personal data.
- It does not publish or deploy the application outside localhost.

## Approach

Use the existing Laravel session authentication and versioned API as the sole data source. An environment-gated local seeder creates deterministic synthetic records. A client-side Next.js dashboard consumes `/me`, organization context and invitation endpoints; it never embeds business data in the UI. This is preferred over a static mock dashboard because every visible state proves the underlying API works, and over a full production console because the current goal is observability during staged development.

## Constraints

- Seeder refuses to run outside `local` or `testing`.
- Demo password comes from `DEMO_USER_PASSWORD`; the repository default is explicitly local-only.
- Invitation tokens are shown only at creation time and never returned by list endpoints.
- Existing user-authored login copy remains preserved.
- Accessibility and responsive behavior follow the established interface baseline.
