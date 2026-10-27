# Activity Catalog and Publishing Design

Date: 2026-10-27
Status: Draft for product approval

## Intent and why

The platform needs a reusable catalog and publishing system for extracurricular activities offered by schools, kindergartens, sports clubs, instructors, camps, and other organizations. Parents must be able to discover an activity, understand its educational and practical details, choose a suitable group and enrollment mode, and later manage the enrollment from one account.

The same data must support the global marketplace, an organization's branded catalog, a direct shareable page, and later an embedded catalog or custom domain. The design must remove the current manual problems: duplicated weekly events, stale participant lists, unclear cancellation effects, duplicated content, and enrollment being incorrectly coupled to advance payment.

## Goals

- Let an organization publish a useful page quickly and enrich it over time.
- Support rich parent pages comparable to the supplied current-site references.
- Separate reusable content from provider, place, group, schedule, capacity, price, and dates.
- Allow internal activities to be published without platform review by authorized organization users.
- Require platform review for the global marketplace or distribution outside the owning organization.
- Keep internal publication active while marketplace review is pending.
- Offer trial, single-visit, full-period, monthly, and package enrollment without implying identical payment timing.
- Use EUR and store money as integer euro cents.
- Reuse locations and instructor profiles across groups.
- Support schedule exceptions, navigation links, and parent notifications.
- Give platform administrators controlled per-organization access to AI writing help.

## Non-goals for the first delivery

- Arbitrary drag-and-drop page building.
- Custom-domain provisioning, full white-label hosting, or third-party embed widgets.
- Automatic AI publication without human review.
- Final payment processing, commissions, wallets, invoicing, or bank reconciliation. The catalog stores terms needed by those later modules.
- The complete parent/child enrollment lifecycle. The catalog exposes the correct group and enrollment choices for that later work.

## Anti-goals

- Do not place content, location, schedule, pricing, capacity, and every date in one oversized activity record.
- Do not duplicate an activity merely to offer another group or location.
- Do not make “enrolled for the period” mean “paid for the period in advance.”
- Do not block an internal catalog while global review is pending.
- Do not let AI invent prices, qualifications, safety claims, schedules, or other facts.
- Do not expose private organization, instructor, or moderation data publicly.

## Constraints

- Build on the existing Next.js web app, Laravel API, PostgreSQL tenant isolation, roles, lifecycle gates, audit trail, and operator review.
- Organization-owned records must follow tenant context and row-level security.
- Existing lifecycle and authorization rules gate creation and publication.
- Public URLs use unique stable slugs and do not expose internal identifiers.
- Bulgarian is the initial UI language; the model must permit later localization.

## Approaches considered

### 1. One large activity record

Initially fast, but it duplicates data and makes multiple groups, providers, locations, and recurring schedules brittle. Rejected because it recreates the current workflow's weaknesses.

### 2. Activity → Offering → Group → Session — selected

Reusable editorial content is separate from commercial offerings, selectable groups, and actual calendar sessions. This is more deliberate to implement but supports the required channels and future attendance, billing, commissions, and cancellations correctly.

### 3. Fully flexible CMS blocks

Maximum visual freedom, but expensive, inconsistent, and too complex for the first marketplace. Deferred. The first version uses curated optional rich sections.

## Core domain model

### Activity

Reusable editorial content belonging to one organization:

- title, summary, category, age/grade range, hero and card images;
- detailed “about” content, benefits, methodology, and lesson flow;
- participation information, FAQ, and gallery;
- optional lead instructor/team presentation;
- content completeness and revision metadata.

Only essential catalog fields are required initially. Rich sections are optional and may be added later.

### Offering

The concrete way an organization offers an activity:

- provider organization and publication channels;
- standard location or delivery mode;
- commercial and participation terms;
- enrollment and pricing options;
- optional lead instructor/team;
- contact and cancellation guidance.

An activity may have multiple offerings when its commercial or organizational context differs.

### Group

The cohort a parent selects:

- name, eligibility, minimum and maximum capacity;
- weekday, start time, duration, and date range;
- default location and assigned instructor profiles;
- availability/waitlist state and enrollment options;
- schedule-generation and cancellation rules.

One offering may show several groups on the same page.

### Session

An actual calendar occurrence generated from a group schedule. A session may override date/time, duration, location, instructors, capacity/status, and change/cancellation reason. Editing one session does not rewrite the series.

### Location

A reusable organization location with name, address, map link/coordinates, public access instructions, visibility, and physical/online/to-be-confirmed type. A group has a default location; sessions may differ. Physical sessions expose a “Navigation” action, and unusual locations may include meeting-point and travel instructions.

### Instructor profile

A reusable organization profile with public biography, organization-supplied qualifications, image, title, and visibility controls. Instructors may belong to several groups; groups may have several instructors. Account access can be linked without duplicating the public profile.

## Parent-facing presentation

### Catalog card

Cards show image, category, title, short summary, age/grade, duration/schedule summary, price in EUR with its unit, availability, and optional “new” or recommended badge.

### Activity page

The curated layout follows the supplied references:

1. Hero with title, category, availability, age, value statement, price summary, image, and action.
2. Practical summary: age, duration, location, group range, and schedule.
3. About the activity.
4. Benefits and skills.
5. Methodology/lesson flow.
6. Groups, schedules, places, and enrollment actions.
7. Enrollment, cancellation, payment, and participation terms.
8. Instructor/team.
9. Gallery.
10. FAQ.

Missing optional sections are omitted cleanly. The platform controls the initial layout for consistency, accessibility, and comparison.

### Organization catalog

Each organization receives a stable address such as `/o/organization-slug`, branded with its name, logo, colors, and selected catalog. Depending on settings and plan, it may show only its own activities, own plus approved partners, or selected marketplace activities. The same parent account will later work across organization catalogs and the marketplace.

Embeds and custom domains will reuse the same API later, after publication permissions are stable.

## Enrollment and pricing

A group may expose simultaneous options: trial session, one-time attendance, whole-period enrollment, fixed monthly fee, and a package of visits. Each has its own EUR amount, unit, eligibility, availability, cancellation rules, and payment timing. Money is stored in euro cents.

The parent selects a group, then an enabled enrollment option. A whole-period enrollment creates continuing group membership; it does not create unrelated weekly reservations and does not automatically require full advance payment.

## Publication and moderation

Publication is tracked independently by channel:

- draft: organization staff only;
- organization catalog: directly publishable by authorized organization users;
- direct/private link or selected community: controlled by organization permissions;
- global marketplace: platform review required;
- another organization's catalog: platform approval plus selection/acceptance by the receiving organization.

Marketplace review does not stop allowed internal publication. A submission may be pending, returned, approved, restricted, or suspended independently. Publication and moderation actions are audited.

After marketplace approval, minor non-substantive corrections may update without a new review. Material changes to title, description, age eligibility, price, terms, instructor presentation, or media create a new reviewable version. The last approved version remains public until the new version is approved; internal publication may show the current organization version immediately.

## Schedule changes and notifications

When date, time, location, or cancellation status changes for a session with participants:

- a short parent-facing reason is required;
- the change is audited;
- a notification event is created for affected parents;
- the parent view marks what changed.

Initial delivery may use in-app/email; additional channels can follow with the enrollment module.

The first catalog slice has no real parent enrollments, so it records change reasons and prepares notification events/interfaces without pretending recipients already exist. Actual recipient resolution and delivery begin with the enrollment slice.

## Content guidance and AI assistance

Optional fields include Bulgarian prompts and examples. Eligible organizations see “Generate with AI” for summaries, benefits, methodology, instructor presentations, and FAQ.

AI uses structured facts, creates only editable drafts, offers shorter/richer/friendlier variants, and must not invent factual or commercial claims. Generated origin is recorded and content can be edited, regenerated, or discarded.

Access is an entitlement rather than only a plan switch. Plans may include trial or recurring quotas, while a platform administrator may enable, disable, limit, or time-box AI for one organization regardless of plan. Usage is metered. External AI service integration can be delivered as a separate approved slice behind a provider boundary.

## Permissions and safety

- Owners/admins manage content, offerings, groups, locations, instructors, and publication according to capabilities.
- Coordinators manage groups/sessions only when authorized.
- Instructors access assigned operational information, not commercial or moderation controls.
- Platform operators review global distribution and control organization-specific AI access.
- Public APIs return only explicitly public and currently publishable fields.
- Tenant selection, lifecycle gates, capability checks, and PostgreSQL RLS remain mandatory.
- Concurrent edits use revision checks to prevent silent overwrites.

## Validation and important states

- Essential fields, one valid offering, and one valid group are required for catalog publication.
- Group schedules must create valid sessions and respect capacity bounds.
- A schedule may contain one or more weekly meeting patterns, including different times and durations by weekday, or an every-two-weeks frequency.
- Generation respects a start/end period and supports holidays, vacations, manually excluded dates, added dates, and per-session moves.
- Instructor overlaps and exclusive-location overlaps block publication but not draft saving. Shared spaces warn rather than block. Online and unconfirmed locations do not create blocking location conflicts.
- Conflict checking is initially limited to records inside the same organization.
- Public price labels must match the price unit.
- Optional sections can be removed without breaking rendering.
- Archiving preserves historical enrollments and audit records.
- Restricted/suspended organizations cannot bypass lifecycle gates via API.

## Delivery sequence

1. First vertical slice: tenant-safe domain/API foundations; organization administration for activity, offering, location, instructor, group, schedule, and EUR price; direct internal publication; and visible organization catalog/activity pages. It deliberately excludes real enrollment and global marketplace publication.
2. Marketplace submission/review with versioned material changes.
3. Cross-organization catalog placements. A later design will define the bilateral proposal/acceptance workflow before this is implemented.
4. Enrollment handoff using groups and enrollment options, including real notification recipients.
5. AI entitlements and assisted writing as a bounded follow-up.
6. Embedding and custom domains after the catalog stabilizes.

Every slice requires authorization/tenant tests, domain tests, web tests, and risk-proportionate browser verification.

## Builder calls that do not change product intent

- Use curated rich-section types, not arbitrary layout JSON.
- Prefer reusable locations/instructors with session overrides.
- Keep slugs stable and relationships on internal immutable IDs.
- Derive availability from capacity, active enrollments, waitlist rules, and publication state.
- Keep AI integration behind a provider boundary.

## Approval checklist

Approval confirms the selected domain structure; reference-inspired page; independent internal/marketplace publication; organization URLs; EUR and multiple enrollment modes; reusable instructors/locations and per-session exceptions; reasons and notifications for material changes; AI drafts with admin-controlled entitlements; and the stated first-version boundaries.
