# ADR 0004: Data classification and privacy controls

## Status

Accepted — 2026-10-27.

## Context

The platform operates in the EU and processes information about children, guardians, schools, attendance and payments. GDPR requires purpose limitation, data minimization, controlled retention, data-subject workflows and demonstrable security. Classification must drive access, logging, export and deletion behavior.

## Decision

Every stored field and document belongs to one of four classes:

| Class | Examples | Minimum controls |
|---|---|---|
| `public` | published organization name, approved activity description, public venue | publish approval, integrity controls, cache allowed |
| `internal` | operational configuration, aggregate capacity, non-public price rules | authenticated role access, tenant scope, normal audit |
| `confidential` | adult contact details, contracts, invoices, bank settlement data | least privilege, encryption in transit/at rest, masked support views, audited export |
| `restricted` | child identity, date of birth, guardian relationship, attendance, medical/accessibility notes, safeguarding records, credentials | explicit purpose and role, field-level minimization, no application logs, audited reads/exports, strict retention and incident priority |

Additional rules:

- Collect only data required for a documented service, legal obligation or safeguarding purpose. Consent is not used where another lawful basis is more appropriate.
- A guardian sees children only through a verified relationship. Staff see only fields needed for the current activity and role; instructors do not receive billing or unrelated family data.
- Production restricted data is never copied to development, tests, analytics or support screenshots. Synthetic fixtures are mandatory outside production.
- Secrets live in the hosting secret manager, never in Git, database content, logs or client bundles.
- Logs use opaque identifiers and redact credentials, contact details, request bodies and payment data.
- Retention is policy-driven by record category and legal basis. Expiry triggers deletion or irreversible anonymization, except records under a documented legal hold.
- Data-subject access, correction, restriction, portability and erasure requests use an identity-verified workflow and retain an audit receipt without retaining the erased content.
- Vendors require documented processor terms, EU/EEA region selection where available, subprocessor review and a transfer assessment where applicable.
- Payment card data is handled only by a compliant payment provider; the platform stores provider references, status and accounting evidence, not full card numbers or security codes.

## Consequences

Privacy requirements become engineering constraints rather than a final checklist. Restricted data features require a privacy and threat review before release. Some deletion requests will result in anonymization rather than removal where accounting or legal retention applies, and the reason must be communicated and recorded.
