# AGENTS.md — общи правила за всички AI агенти (Codex, Claude и др.)

Този файл е задължителен за всеки агент, който работи в хранилището. Codex го чете автоматично; `CLAUDE.md` го импортира. Ако правило тук противоречи на стар план в `docs/superpowers/` или `outputs/`, важи този файл.

Преди да започнеш задача прочети:
1. `docs/process/WORKBOARD.md` — коя задача е твоя, кой какви файлове държи в момента.
2. `docs/process/WORKFLOW.md` — как работим, кога какви проверки се пускат.
3. `docs/process/DECISIONS.md` — взетите продуктови и технически решения.

## Кой какво прави

- **Claude** — основен изпълнител: планира системата със собственика, пише backend и web, пуска проверките и проверява на живо в браузъра.
- **Codex** — по-леки, ясно описани задачи и втори поглед (review) на големите етапи.
- **Собственикът** — приоритети, продуктови решения, merge.
- Който пише кода, не прави review на собствения си код (`docs/process/reviewers/`).

## Продуктът накратко

Multi-tenant SaaS и marketplace за извънкласни дейности, лагери и курсове за деца в България. Потребители: оператор на платформата, организации (клубове, школи, училища, детски градини), родители/настойници, преподаватели, счетоводители. Основен език — български.

## Стек и структура

- `platform/apps/api` — Laravel 13, PHP 8.4, PostgreSQL 17 с Row Level Security, Redis, Sanctum session auth.
- `platform/apps/web` — Next.js 16 (App Router), React 19, TypeScript strict, CSS Modules.
- `platform/packages/` — `design-system` (tokens и компоненти), `api-client` (генериран от OpenAPI). В момента празни — виж WORKBOARD.
- `platform/docs/architecture/` — ADR-и. `platform/compose.yaml` — локална среда.
- Домейни в API: `app/Domain/{Identity,Organizations,Catalog,Enrollment,Audit}`. Домейн не вика вътрешности на друг домейн — само неговите Actions/Services.

## Команди

```bash
cd platform && docker compose up --build                        # локална среда: web :3000, api :8080, mailpit :8025; worker праща имейлите всяка минута
docker compose exec api php artisan migrate --database=pgsql_admin --force   # миграциите — с ролята-собственик; API-то работи с ограничената роля
docker compose exec api php artisan db:seed --class='Database\Seeders\LocalDemoSeeder' --force

# API (от platform/apps/api)
php artisan test --filter=<Име>        # бърза проверка на засегнатото
php artisan test --group=tenant-isolation
composer lint | composer analyse | composer verify   # verify = Pint + PHPStan + всички тестове

# Web (от platform/)
pnpm --filter @progressive/web lint
pnpm --filter @progressive/web typecheck
pnpm --filter @progressive/web test
pnpm verify                            # docs:check + lint + typecheck + test + build
```

## Git правила (задължителни)

- `main` е защитен. Никога не commit-вай директно в него и не прави force push.
- Една задача = един клон = един PR. Име на клона: `codex/<кратко-име>` или `claude/<кратко-име>`.
- Никога не работи в клон или worktree, който в WORKBOARD е записан на друг агент.
- Преди старт: обнови от `main`, запиши задачата в WORKBOARD (агент, клон, файлове/области). След merge — премахни реда.
- Commit съобщения: Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`), на английски.
- Не commit-вай: `.env*` (освен `.env.example`), `.pnpm-store/`, `.superpowers/`, `test-results/`, лични данни, реални експорти.

## „Горещи“ файлове — само един отворен PR наведнъж

Ако някой от тези е зает в WORKBOARD, изчакай или вземи друга задача:
- `platform/apps/api/database/migrations/**` (нови миграции само с текуща дата/час, никога не редактирай вече merge-ната миграция)
- `platform/apps/api/routes/api.php`
- `platform/docs/api/openapi.yaml` и `platform/packages/api-client/**`
- `pnpm-lock.yaml`, `composer.lock`, `package.json`, `composer.json` (зависимости — в отделен PR)
- design tokens: `platform/packages/design-system/**`, `platform/apps/web/src/app/globals.css`
- `.github/workflows/**`, `platform/compose.yaml`, `AGENTS.md`, `CLAUDE.md`

## Сигурност — неотменими правила

- Всеки tenant-bound запис има `organization_id`; новите таблици получават RLS политика (`ENABLE` + `FORCE`) в същия PR и тест за cross-tenant достъп.
- Tenant се взима само от проверено активно членство (`ResolveTenant`), никога от request body.
- Чужд tenant връща 404, никога данни. Отказът е по подразбиране.
- Никога не логвай пароли, токени, картови данни, медицински данни или лични данни на деца.
- Без нови зависимости без нужда; всяка нова зависимост се обосновава в PR описанието.
- Тайни само в `.env` / secret store. В кода и документацията — само примерни стойности.
- Админ и финансови роли изискват MFA преди пилот. Финансови таблици — само след решение в DECISIONS.md.
- Без лицево разпознаване, постоянно GPS проследяване или скрито профилиране.

## UI/UX правила

- Целият потребителски текст е на български, ясен, без технически термини (`catalog.view`, `tenant` и т.н. не се показват на потребителя).
- Цветове, шрифтове, отстояния, радиуси — само чрез design tokens (CSS custom properties). Без hardcoded hex стойности в нови модули.
- WCAG 2.2 AA: видими labels, фокус от клавиатура, контраст, статус не само с цвят.
- Mobile-first; проверка на 390, 768 и 1440 px.
- Навигацията (shell) не изчезва при смяна на страница; за зареждане — skeleton, не празен екран.
- Таблата показват действия („какво чака мен“), не технически статистики.

## Три скорости на работа

| Скорост | За какво | Изискване |
|---|---|---|
| Бърза | текстове, цветове/tokens, отстояния, менюта, икони, документация | lint + typecheck + визуална проверка; PR, merge след зелен CI |
| Стандартна | нов екран върху съществуващо API, компонент, рефакторинг в един модул | кратко описание в PR + тестове за засегнатото; UX ревюър само при нов или изцяло преработен екран, веднъж на етапа |
| Строга | auth, tenant/RLS, роли, миграции, плащания, детски данни, audit, публично API | спецификация → ревюър архитект (веднъж) → план → TDD → ревюър по сигурност веднъж на етапа (на PR — при миграции, RLS, права, публичен маршрут); голям етап — и втори поглед от Codex |

Ревюърите са независими агенти с готови шаблони в `docs/process/reviewers/` (архитект, сигурност, UX). Ревюърът само чете; поправя авторът. Един пълен кръг на етап; поправките ги проверява авторът по списъка; тесен вход и само сериозни находки (`docs/process/WORKFLOW.md`, т. 10).

Superpowers потокът (`subagent-driven-development`) се ползва само за строгата скорост. За бързата и стандартната — директна работа без briefs, reports и review diff-ове.

## Definition of done

- Тестовете за засегнатото минават локално; CI е зелен.
- Няма нови предупреждения от lint/PHPStan/TypeScript.
- PR описанието казва: какво, защо, как е проверено, рискове. За UI — screenshot.
- WORKBOARD е обновен. Ако е взето решение — записано е в DECISIONS.md.
- Ако задачата промени команди или правила — обновен е този файл.

## Когато не си сигурен

Спри и запиши въпроса в PR описанието или в WORKBOARD → „Въпроси към собственика“. Не избирай сам платежен доставчик, не създавай платени ресурси, не изтривай данни и не променяй правила за сигурност без изрично одобрение.
