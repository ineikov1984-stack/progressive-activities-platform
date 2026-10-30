# ADR 0005: Enrollment emails from an outbox, sent by a tenant-aware command

## Status

Accepted — 2026-10-30. Specification: `docs/product/enrollment-contacts-and-emails.md`, т. 6. Mock-up approved by the owner on 30.10.2026.

## Context

Parents must hear about decisions and changes (approved, waiting, rejected, moved, paused, ended), and the team about new applications and about steps a parent took. An email must never go out for a change that was rolled back, must not leak another organization's data, must not put contact details or children's data into logs or queues, and must survive a slow or failing mail server. The application has no queue worker yet, and Laravel's queue tables have no RLS.

## Decision

- **Outbox.** Every enrollment change writes an `enrollment_events` row in its own transaction (already the case). No email is created by the change itself.
- **Deliveries.** A command, `enrollment:deliver-events`, scheduled every minute (`withoutOverlapping`, `onOneServer`), expands new events into `enrollment_event_deliveries` — one row per recipient, keyed by `(event, audience, recipient_key)` — and sets `expanded_at` in the same transaction. Recipients are keys (`user:<id>`, `offering_contact`, `organization_contact`), not addresses; the address is read when the email is composed.
- **Tenant per step.** The command has no user. `pending_enrollment_delivery_tenants()` (SECURITY DEFINER) tells it only which organizations have work. Each step for an organization — expand, claim, compose, mark — runs in its own transaction with `app.current_organization_id` set locally and the tenant context selected, cleared afterwards. Reading outside such a transaction returns nothing under RLS.
- **Claim, compose, send, mark.** Claim takes up to 10 ready rows with `FOR UPDATE SKIP LOCKED`, sets a 5-minute lease and counts the attempt. Compose builds the email in memory from rows read under RLS; the reason quoted to the parent comes from the immutable transition, never from the application's current text. Sending happens after the read transaction, synchronously (`Mail::send`, no `ShouldQueue`), so the finished text never sits in `jobs` or `failed_jobs`. Mark sets `sent`, or `pending` with a back-off of 1, 5, 30, 120 minutes, and `failed` after the 5th attempt. Expired leases return to `pending`.
- **What is not sent.** An application event whose application has moved on (`superseded`); an unconfirmed parent address (`unverified_email`); a team without any address (`no_recipient`); an event older than 72 hours (`too_old`); event types without a template (no row).
- **Words and looks.** One HTML and one text template lay out worded content. Parent emails carry the organization's colour and logo (from its catalog profile, linked through the web app's public `/backend` proxy at `WEB_URL` — the API host need not be public — and only when the file is stored; initials otherwise; text colour chosen for contrast), the child's first name, the activity, the group, days and place — never a surname, class, birth date, the parent's note, a pause reason or an internal note. Team emails carry the activity, the group and a link — never the child or the parent. `From` is "<organization> чрез <platform>"; `Reply-To` is the organization's contact address, never the platform's. The platform's name (`PLATFORM_NAME`) and the web address for links (`WEB_URL`) are settings.
- **Logs.** A transport error is caught; only its class and SMTP code are stored; nothing is reported or logged with an address or text. In production the command refuses a mailer that keeps the text (`log`, `array`, or either inside a failover or round-robin chain) and a `WEB_URL` without https.
- **Robustness.** Each event is expanded in its own transaction; one that cannot be expanded is set aside with identifiers only in the log. A delivery whose email cannot be composed is retried like a transport error. Compose and mark act only while the claim still holds the lease (same attempt, lease not expired); rows taken but not sent when the run is out of time go back without counting the attempt. The SMTP timeout is 15 s, so a batch of 10 fits in the 5-minute lease. The scheduler's overlap lock expires after 10 minutes, so a killed run does not stop emails for long.
- **The parent's own words** (a reason for ending or skipping can be about health) are never quoted back in an email; only what the team wrote for the parent is.

## Consequences

- Delivery is "at least once": a duplicate is possible only if the process stops between sending and marking. The Message-ID comes from the delivery, so a duplicate is recognisable.
- Locally the command sends to Mailpit; staging uses Mailpit or a provider's sandbox; the production provider (EU data, data processing agreement) is chosen by the owner before the pilot.
- A `worker` container that runs the scheduler is added to `compose.yaml` in a separate change (hot file). Until then the command can be run by hand.
- Team messages (stage Б, PR 5) create their deliveries in their own transaction and use the same sending loop.
