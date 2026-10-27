# ADR 0002: Organization tenancy and shared identities

## Status

Accepted — 2026-10-27.

## Context

The platform serves the marketplace operator, Progressive Academy, schools, clubs, studios and independent providers. A parent can belong to one family whose children attend different schools and providers. Some schools use only a private workspace; others also publish selected activities to the marketplace.

## Decision

Use central identities with organization memberships, not separate accounts per school.

- `users` is global and represents an authenticated adult or staff identity.
- `organizations` represents the platform operator, academy, school, club or provider.
- `organization_memberships` assigns a user a role and lifecycle state inside one organization.
- Family and guardian relationships are global enough to work across organizations, but access to a child always requires an active guardian relationship or an explicitly authorized staff purpose.
- Every tenant-owned row contains a non-null `organization_id`; shared reference data is explicitly marked as platform-owned.
- A request obtains its organization only from a verified host/subdomain, selected membership or signed integration context. Client-supplied organization identifiers never establish authorization.
- One request-scoped `TenantContext` exposes the resolved organization. Policies and repositories fail closed when it is absent.
- Operator support access is time-bound, purpose-bound and audited; it never silently impersonates a tenant.
- School visibility is per offering: `private`, `community` (verified school families) or `marketplace`. Subscription plan controls available features, not authorization shortcuts.
- Database queries, unique constraints, caches, jobs, exports and files include tenant scope. Cross-tenant reporting runs only in an operator context with an explicit permission and audit event.

## Consequences

A guardian keeps one account and one family view across children and providers, while organizations remain isolated. Authorization is more complex than separate databases, so tenant-leak tests and scoped repositories are release gates. Moving a tenant to dedicated storage later remains possible because organization ownership is explicit on every tenant record.
