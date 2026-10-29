# WORKBOARD — кой какво прави

Правила: виж `WORKFLOW.md`. Обновява се от агента при старт и край на задача.

## В работа

| Задача | Агент | Клон | Заети области / горещи файлове | Статус / следваща стъпка |
|---|---|---|---|---|
| Процес за Codex + Claude (AGENTS.md, CLAUDE.md, docs/process) | Claude | platform-foundation | AGENTS.md, CLAUDE.md, docs/process, .github/pull_request_template.md | готово локално; чака GitHub хранилище |

## Опашка (по приоритет)

1. **GitHub** — private хранилище, push на `platform-foundation` като `main`, защита на `main` (PR + зелен CI). *Собственик + Claude.*
2. **Почистване** (бърза) — премахване на `platform/platform/`, на `apps/web/pnpm-lock.yaml` и `apps/web/pnpm-workspace.yaml`, на Laravel workflow-ите в `apps/api/.github/`; проверка, че CI е само в корена. *Codex.*
3. **Сигурност на proxy-то** (строга) — allowlist в `apps/web/src/app/backend/[...path]/route.ts` (`api/v1/*`, `sanctum/csrf-cookie`); security headers и CSP в `next.config.ts`; gitleaks в CI. *Codex пише, Claude review.*
4. **Design tokens и базови компоненти** (стандартна) — tokens в `packages/design-system`, единна палитра за login и администрация, Button/Input/Card/Badge/Skeleton. Завършва текущия клон „admin color system“. *Claude.*
5. **UX поправки в администрацията** (бърза/стандартна) — shell остава при навигация + skeleton; едно активно меню; без технически термини (`catalog.view`, „Tenant защита“); бъдещите менюта скрити или „Скоро“; премахване на дублиращите се линкове; табло „какво чака мен“. *Claude.*
6. **Задача 9: OpenAPI 3.1 + генериран TypeScript клиент** (строга) — contract test, `packages/api-client`, замяна на ръчните `api.ts`. *Codex пише, Claude review.*
7. **Актуализация на плана** — `outputs/` и roadmap-ът да отразяват реалния код (Activity/VisitOffer вместо Service/ServiceVersion, вече направеното по записванията). *Claude + собственик.*
8. **Задача 10: staging** (строга) — само след одобрение на разходите. *Codex.*

## Въпроси към собственика

- Кой GitHub акаунт и как да се казва хранилището?
- Името и домейнът на marketplace-а (от плана, т. 4).

## Наскоро завършено

- 29.10.2026 — подсигурена работа: клон `platform-foundation` + локален backup bundle (Claude).
