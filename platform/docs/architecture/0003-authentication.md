# ADR 0003: Authentication for web, mobile and partners

## Status

Accepted — 2026-10-27.

## Context

The first client is a first-party web application embedded or linked from WordPress. Future clients include mobile applications, school sites and approved partner integrations. The platform processes children's data and financial operations, so account takeover and excessive token lifetime are unacceptable.

## Decision

- The first-party web application uses a server-managed, `HttpOnly`, `Secure`, `SameSite=Lax` session cookie with CSRF protection. Authentication tokens are never stored in browser local storage.
- Passwords use Laravel's current strong adaptive hash. Login, password reset and verification endpoints are rate-limited and return non-enumerating responses.
- Email verification is required before booking or joining an organization. Sensitive staff roles require multi-factor authentication when the MFA milestone is enabled; the schema and authorization flow must be MFA-ready from the first release.
- Sessions can be listed and revoked. Password change, suspected compromise and staff deactivation revoke relevant sessions.
- Mobile clients will use OAuth 2.1 Authorization Code with PKCE through a reviewed authorization component; short-lived access tokens and rotated refresh tokens are stored in the device secure store.
- Partner machine integrations use narrowly scoped, expiring credentials assigned to an organization, with rotation, IP/rate controls where appropriate and complete audit trails. They never reuse a human password.
- WordPress consumes public catalog APIs anonymously and starts authenticated flows on the platform origin. It does not receive family sessions or restricted child data.
- Authorization is enforced by the API on every request; the interface may hide actions but is never the security boundary.

## Consequences

The web flow is resistant to token theft through injected scripts and supports later mobile and partner channels without weakening the first-party model. MFA and OAuth add later implementation work, while the initial contracts prevent incompatible shortcuts. Account recovery and support procedures must receive the same threat review as login.
