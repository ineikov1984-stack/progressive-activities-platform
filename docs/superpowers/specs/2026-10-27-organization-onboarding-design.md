# Organization Onboarding and Operator Approval Design

## Intent

Build the controlled, invite-only onboarding path for the pilot so a prospective school, club or provider can complete its organization application while the platform operator remains the only party able to approve, activate, restrict or suspend it.

## Goals

- Support the lifecycle `applicant → review → approved → active → restricted → suspended` with explicit allowed transitions.
- Let an organization owner submit an invited applicant for review and safely resubmit after requested changes.
- Give the applicant a Bulgarian status, a public explanation and a clear next action.
- Give platform administrators a separate operator queue with internal risk notes and review actions.
- Prevent self-approval and lost updates through role checks and optimistic versioning.
- Record every submission and review decision in the immutable audit trail from Task 6.
- Provide synthetic local demo records only; no real partner documents or personal data are introduced.

## Non-goals

- Public self-registration, automated verification and bulk onboarding are deferred beyond the pilot.
- Document upload, bank-account verification, contracts and qualification checks are not implemented in this task; the data model only leaves a clean extension point for them.
- Approval does not publish marketplace activities; activity approval remains Task 8.
- This task does not implement billing plans or partner settlements.

## Chosen approach

Use an invite-only staged application on the existing organization record. The operator creates or invites the applicant organization; its owner completes the application and submits it. A different user with the global `platform_admin` role reviews the queue in a dedicated operator route.

For the pilot, the operator console creates the applicant organization and a one-time initial-owner invitation by reusing the existing hashed-token and expiry rules. Ordinary tenant invitations continue to forbid assigning `organization_owner`; only this audited operator bootstrap flow may create the initial owner membership.

Alternatives considered:

- Public self-service registration was rejected for the pilot because identity, spam and document-validation controls are not yet ready.
- Fully operator-entered organizations were rejected because it creates avoidable manual work and does not establish the future partner workflow.

## Lifecycle and transitions

- `applicant → review`: organization owner submits a complete application.
- `review → applicant`: operator requests changes and supplies a public reason and next action.
- `review → approved`: operator accepts the application.
- `approved → active`: operator activates the partner for operational use.
- `active → restricted`: operator applies a limited-access state with a public reason.
- `restricted → active`: operator removes the restriction after review.
- `active|restricted|approved|review|applicant → suspended`: operator suspends the organization with a public reason.
- `suspended → review`: operator reopens the application for a new review.

Repeated submission while already in `review` is idempotent and does not create another state change. All other transitions fail closed with a validation conflict.

Status controls the accessible surface:

- `applicant` and `review` can sign in only to the onboarding/status workspace and may not use operational modules.
- `approved` remains in onboarding with a visible message that activation is pending.
- `active` receives the normal organization workspace and operational APIs.
- `restricted` receives read-only operational access plus the remediation/status workspace; new publishing, booking and financial mutations fail closed.
- `suspended` receives only the status/contact workspace and no operational tenant data.

Task 7 establishes the central status gate and onboarding exceptions. Later modules must consume that gate instead of inventing their own status checks.

## Data model and privacy boundary

The organization stores `status`, integer `review_version`, `public_review_reason`, `next_action`, `submitted_at`, `reviewed_at` and `reviewed_by`. Internal risk notes live in a separate operator-only review table and are never returned by applicant endpoints.

The initial applicant form stores only organization-level pilot fields needed to test the flow: legal/display name, organization type, registration identifier and public contact email. Banking, representative identity, contracts, insurance and qualification files are deferred until their own protected document model exists.

Existing organizations are migrated as `active` with review version `1`, preserving their current access. Newly bootstrapped applicants start as `applicant` with review version `1`.

Every mutation checks the supplied `review_version`. A stale version returns HTTP `409` with the current safe state, preventing one reviewer from silently overwriting another decision.

## Authorization and tenancy

- Organization owners and organization administrators may view the applicant-safe state; only owners may submit.
- A platform administrator with any active membership in the target organization can never review or approve that organization, regardless of the role currently displayed in the interface.
- Operator endpoints require the global `platform_admin` role and do not trust an organization identifier from ordinary tenant context.
- Operator list/read responses expose internal notes only to platform administrators.
- Applicant endpoints never expose internal notes, reviewer identity details or information about other tenants.

## API and audit

- `GET /api/v1/organization-application` returns the active tenant's safe application state.
- `PATCH /api/v1/organization-application` saves the allowed draft fields with an expected version; editing is allowed in `applicant` and rejected while the record is in review.
- `POST /api/v1/organization-application/submit` validates the version and moves `applicant` to `review`.
- `POST /api/v1/operator/organization-applications` creates an applicant organization and its one-time initial-owner invitation.
- `GET /api/v1/operator/organization-applications` returns the operator review queue.
- `POST /api/v1/operator/organization-applications/{organization}/review` accepts a transition, expected version, public reason, next action and optional internal note.

Audit actions cover application submitted, changes requested, approved, activated, restricted, restriction removed, suspended and reopened. Safe metadata contains only previous/new status and review version; form payloads and internal notes are not copied into audit metadata.

Public reason and next action are mandatory for requesting changes and restricting access. Public reason is mandatory for suspension; its next action defaults to contacting platform support. Approval and activation use standard Bulgarian status copy unless the operator supplies an additional public note. Reopening and removing a restriction may include an optional public explanation.

Internal notes are append-only review history records: each review action creates a new row containing operator, transition, note and timestamp. They are not edited in place and remain operator-only.

## Interfaces

### Applicant view

Add an onboarding card to the organization dashboard with Bulgarian status text and icon, public reason, next action, current version and a submit/resubmit button when allowed. The active organization and current role remain visible.

### Operator view

Add `/operator/organizations` as a visually distinct operator console. It shows the active global role, review queue, selected application's safe organization fields, internal note area and only the actions valid for the current status. Actions require a confirmation explaining the resulting status.

## Errors and resilience

- Invalid transitions return `409` with a Bulgarian message.
- Stale versions return `409` and the current safe version/state.
- Missing platform-operator permission returns `403` without revealing queue data.
- Validation errors use `422`; unknown organizations use `404`.
- Submission and its audit event, and review decision and its audit event, each share one database transaction.

## Verification

- Feature tests cover every allowed and rejected transition, repeated submit, stale concurrent review, tenant self-approval, cross-tenant access and audit events.
- API presentation tests prove internal notes never appear in applicant responses.
- Web unit and browser tests cover applicant status and the operator approval flow with keyboard-accessible controls and visible focus.
