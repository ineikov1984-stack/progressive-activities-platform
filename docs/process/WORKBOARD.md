# WORKBOARD — кой какво прави

Правила: виж `WORKFLOW.md`. Обновява се от агента при старт и край на задача.

## В работа

| Задача | Агент | Клон | Заети области / горещи файлове | Статус / следваща стъпка |
|---|---|---|---|---|
| Почистване на остатъци + възпроизводим web образ | Claude | claude/cleanup-leftovers | `.github/workflows/**`, `platform/compose.yaml`, `apps/web/Dockerfile`, `apps/api/composer.json` | PR отворен |

## Опашка (по приоритет)

1. **Защита на `main`** — ruleset „PR задължителен, без force push и изтриване“. Claude не може да го включи през API; прави се от собственика в GitHub → Settings → Rules (за private хранилище изисква GitHub Pro). Дотогава правилото се спазва чрез AGENTS.md. *Собственик.*
2. **Сигурност на proxy-то** (строга) — allowlist в `apps/web/src/app/backend/[...path]/route.ts` (`api/v1/*`, `sanctum/csrf-cookie`); security headers и CSP в `next.config.ts`; gitleaks в CI. *Codex пише, Claude review.*
3. **Design tokens и базови компоненти** (стандартна) — tokens в `packages/design-system`, единна палитра за login и администрация, Button/Input/Card/Badge/Skeleton. Завършва текущия клон „admin color system“. *Claude.*
4. **UX поправки в администрацията** (бърза/стандартна) — shell остава при навигация + skeleton; едно активно меню; без технически термини (`catalog.view`, „Tenant защита“); бъдещите менюта скрити или „Скоро“; премахване на дублиращите се линкове; табло „какво чака мен“. *Claude.*
5. **Задача 9: OpenAPI 3.1 + генериран TypeScript клиент** (строга) — contract test, `packages/api-client`, замяна на ръчните `api.ts`. *Codex пише, Claude review.*
6. **Актуализация на плана** — `outputs/` и roadmap-ът да отразяват реалния код (Activity/VisitOffer вместо Service/ServiceVersion, вече направеното по записванията). *Claude + собственик.*
7. **Задача 10: staging** (строга) — само след одобрение на разходите. *Codex.*

## Въпроси към собственика

- Името и домейнът на marketplace-а (от плана, т. 4).

## Наскоро завършено

- 29.10.2026 — PR #1: CI с видими грешки, правилен обхват на браузърните тестове, `symfony/mime` 8.1.10 (CVE-2026-45070) (Claude).
- 29.10.2026 — кодът е в GitHub: `ineikov1984-stack/progressive-activities-platform`, клон `main` (Claude + собственик).
- 29.10.2026 — процес за Codex + Claude: AGENTS.md, CLAUDE.md, docs/process (Claude).
- 29.10.2026 — подсигурена работа: клон `platform-foundation` + локален backup bundle (Claude).
