# Environment matrix

This document lists configuration boundaries only. It contains placeholders, never credentials. Real values are injected through local untracked files or the managed hosting secret store.

| Concern | Local | CI | Staging | Production |
|---|---|---|---|---|
| Purpose | developer feedback | automated verification | acceptance and integrations | live customer service |
| Data | synthetic fixtures | ephemeral synthetic data | synthetic/anonymized pilot data | authorized live data |
| Web/API | Docker on localhost | isolated job services | managed EU-region app | managed EU-region app with scaling |
| PostgreSQL | Docker volume | ephemeral service | managed EU database | managed EU HA database, PITR enabled |
| Redis | Docker volume | ephemeral service | managed cache/queue | managed cache/queue with persistence policy |
| Mail | Mailpit, no delivery | captured/no delivery | sandbox or allow-listed recipients | approved transactional provider |
| Object storage | local emulator when needed | ephemeral | private EU bucket | private EU bucket with lifecycle rules |
| Secrets | untracked `.env` from examples | CI secret store | hosting secret store | hosting secret store with rotation |
| Payments | provider sandbox | mocked/sandbox | provider sandbox | live provider account after legal review |
| Observability | local logs, redacted | job logs, redacted | EU-region monitoring | EU-region monitoring and alerts |
| Backups | disposable | none | scheduled restore test | encrypted backups and documented restore drills |

## Configuration rules

- Environment variables are validated at startup; the application fails closed when a required setting is absent.
- No production credential is accepted in local, CI or staging configuration.
- Public browser variables use an explicit allow-list and never contain secrets.
- Production and staging use separate accounts, databases, buckets, encryption keys and payment credentials.
- Access follows least privilege, named accounts and MFA. Emergency access is time-bound and audited.
- Region selection, retention, recovery objectives and subprocessors are recorded before production launch.
