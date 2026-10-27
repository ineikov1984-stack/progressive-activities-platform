# Платформена основа и първи вертикален поток — план за изпълнение

> **За агентни изпълнители:** ЗАДЪЛЖИТЕЛНО ПОДУМЕНИЕ: използвайте `superpowers:subagent-driven-development` (препоръчано) или `superpowers:executing-plans`, като изпълнявате задачите една по една. Стъпките се проследяват с checkbox (`- [ ]`).

**Цел:** За три седмици да се изгради сигурна, deploy-ваема основа на multi-tenant платформата и първи работещ поток: оператор създава/одобрява организация, партньор влиза, създава чернова на дейност и я изпраща за преглед, без достъп до данни на друга организация.

**Архитектура:** Модулен монолит с Laravel API и отделен Next.js web клиент в общо monorepo. PostgreSQL е източник на истина; tenant изолацията се налага едновременно в application policies и чрез PostgreSQL Row Level Security. Публичният сайт, бъдещите мобилни приложения и административните интерфейси използват едни и същи versioned бизнес API-та.

**Технологичен стек:** PHP 8.4, Laravel 13, TypeScript, Next.js 16 Active LTS, React 19, PostgreSQL + PostGIS, Redis, S3-compatible object storage, Docker Compose за локална среда, Pest/PHPUnit, Vitest, Playwright, OpenAPI 3.1, GitHub Actions, DigitalOcean App Platform/Managed PostgreSQL Frankfurt.

**Спецификация:** `outputs/technical-assignment-education-platform.md`

## Глобални ограничения

- Българският е основен език; английският се поддържа структурно, без да е задължително целият MVP да е преведен.
- Всички timestamps се пазят в UTC и се визуализират в `Europe/Sofia` според организацията.
- Всички tenant-bound записи съдържат `organization_id`; cross-tenant достъпът се отказва по подразбиране.
- Development и staging използват само синтетични данни; реални Fitsys данни не се копират.
- Пълни картови данни, пароли, access tokens, медицински данни и детски лични данни не се записват в логове.
- Администраторските и финансовите роли изискват MFA преди production пилота.
- Финансовите таблици се проектират след писменото Phase 0 решение за PSP, merchant of record, фактуриране и settlement.
- Няма лицево разпознаване, постоянно GPS проследяване или скрито профилиране по местоположение.
- API е versioned под `/api/v1`; публичните договори се описват с OpenAPI 3.1.
- WCAG 2.2 AA е минималното изискване за основните клиентски потоци.

## Фокус при прегледа

1. Заявка с валиден потребител, но чужд `organization_id`, трябва да върне 404/403 и никога данни.
2. Потребител с членство в две организации трябва да действа само в изрично избрания tenant и смяната да се одитира.
3. Повторна покана, изтекъл token или вече използван token не трябва да създава второ членство.
4. Съществена редакция на одобрена дейност трябва да създаде нова версия за преглед, без да променя публикуваната версия.
5. Неуспешна външна услуга или повторен request не трябва да дублира организация, покана, audit event или submit действие.

---

## 1. Master roadmap за 5–6 месеца

Разработката се разделя на отделни, проверими планове. Следващ план се детайлизира едва когато предходният вертикален поток е демонстриран.

| Период | Самостоятелен план | Проверим резултат |
|---|---|---|
| Седмици 1–3 | Платформена основа и първи вертикален поток | tenant, роли, организация, покана и чернова на дейност |
| Седмици 4–6 | Семейства и настойници | семейство с две деца и двама равноправни настойници |
| Седмици 7–9 | Каталог и marketplace | публично търсене по дете, категория и локация |
| Седмици 10–12 | Графици, групи и ресурси | повтаряем график, капацитет и конфликт на ресурс |
| Седмици 13–15 | Резервации, чакащи и откази | пробно/единично записване и правилен навременен/късен отказ |
| Седмици 16–18 | Присъствие и спорове | mobile-first списък, заключване и корекция с одит |
| Седмици 19–21 | Плащане, ledger и извлечения | проследима тестова транзакция и месечно задължение |
| Седмици 22–23 | Партньорски разчети и фактуриране | заключен период и проверим settlement отчет |
| Седмици 24–25 | WordPress интеграция и известия | live цена/места в сайта и транзакционни съобщения |
| Седмица 26 | Миграционен dry run, security и pilot readiness | restore/load/tenant тестове и пилотен checklist |

Правният, платежният, счетоводният и GDPR поток вървят паралелно от седмица 1 и са release gates, не задачи „за края“.

## 2. Предложена структура на файловете

```text
platform/
├── apps/
│   ├── api/                         # Laravel 13
│   │   ├── app/Domain/Identity/
│   │   ├── app/Domain/Organizations/
│   │   ├── app/Domain/Catalog/
│   │   ├── app/Domain/Audit/
│   │   ├── app/Http/Controllers/Api/V1/
│   │   ├── app/Http/Middleware/
│   │   ├── database/migrations/
│   │   ├── routes/api.php
│   │   └── tests/{Feature,Unit}/
│   └── web/                         # Next.js 16
│       ├── app/(auth)/
│       ├── app/(workspace)/
│       ├── components/
│       ├── features/{auth,organizations,catalog}/
│       ├── lib/api/
│       └── tests/
├── packages/
│   ├── api-client/                  # генериран TypeScript клиент
│   ├── design-system/               # tokens и достъпни компоненти
│   └── config/                      # споделени TS/ESLint настройки
├── infra/
│   ├── docker/
│   └── digitalocean/
├── docs/
│   ├── architecture/
│   ├── api/openapi.yaml
│   └── operations/
├── .github/workflows/ci.yml
├── compose.yaml
└── README.md
```

## 3. Подробен план за седмици 1–3

### Задача 1: Monorepo, локална среда и автоматични проверки

**Файлове:**
- Create: `platform/apps/api/`
- Create: `platform/apps/web/`
- Create: `platform/packages/api-client/`
- Create: `platform/packages/design-system/`
- Create: `platform/compose.yaml`
- Create: `platform/.github/workflows/ci.yml`
- Create: `platform/README.md`

**Интерфейси:**
- Produces: локални услуги `api`, `web`, `postgres`, `redis`, `mailpit`, health endpoints `/up` и `/api/v1/health`.

- [ ] **Стъпка 1: Създайте failing smoke проверки**

API тестът очаква `GET /api/v1/health` да върне `200` и `{ "status": "ok" }`; Playwright очаква web началната страница да съдържа „Progressive Activities“.

- [ ] **Стъпка 2: Стартирайте проверките и потвърдете, че се провалят**

Run: `cd platform && docker compose run --rm api php artisan test --filter=Health && pnpm --dir apps/web test:e2e --grep "home shell"`

Expected: FAIL, защото приложенията още не съществуват.

- [ ] **Стъпка 3: Създайте Laravel 13 и Next.js 16 приложенията и локалните услуги**

Заключете major версиите; включете PHPStan/Larastan, Pint, ESLint, TypeScript strict, Vitest и Playwright. Добавете `.env.example` само с примерни стойности.

- [ ] **Стъпка 4: Добавете CI pipeline**

Pipeline ред: dependency audit → lint/static analysis → unit/feature tests → Next build → Playwright smoke tests. Никакви production secrets в CI файла.

- [ ] **Стъпка 5: Стартирайте пълната проверка**

Run: `cd platform && docker compose up -d && composer --working-dir=apps/api verify && pnpm verify`

Expected: всички проверки PASS и двата health endpoint-а отговарят.

- [ ] **Стъпка 6: Commit**

`git commit -m "chore: scaffold platform monorepo and CI"`

### Задача 2: Архитектурни решения и конфигурационни граници

**Файлове:**
- Create: `platform/docs/architecture/0001-modular-monolith.md`
- Create: `platform/docs/architecture/0002-tenancy.md`
- Create: `platform/docs/architecture/0003-authentication.md`
- Create: `platform/docs/architecture/0004-data-classification.md`
- Create: `platform/docs/operations/environment-matrix.md`

**Интерфейси:**
- Produces: правила за модулни зависимости, tenant context, auth модел, класификация `public/internal/confidential/restricted`.

- [ ] **Стъпка 1: Напишете architecture lint тест**

Тестът проверява, че четирите ADR файла съдържат `Status`, `Decision`, `Consequences` и че environment matrix не съдържа истински credentials.

- [ ] **Стъпка 2: Потвърдете failing резултата**

Run: `cd platform && pnpm docs:check`

Expected: FAIL с липсващи ADR файлове.

- [ ] **Стъпка 3: Документирайте решенията**

Забранете директни зависимости между домейни извън публичните им application interfaces. Опишете central identity + organization memberships, cookie session за first-party web и token flow за бъдещи mobile/partners.

- [ ] **Стъпка 4: Стартирайте проверката**

Run: `cd platform && pnpm docs:check`

Expected: PASS.

- [ ] **Стъпка 5: Commit**

`git commit -m "docs: record platform architecture decisions"`

### Задача 3: Identity, сигурен вход и MFA-ready модел

**Файлове:**
- Create: `platform/apps/api/app/Domain/Identity/Models/User.php`
- Create: `platform/apps/api/app/Domain/Identity/Actions/RegisterUser.php`
- Create: `platform/apps/api/app/Domain/Identity/Actions/AcceptInvitation.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/AuthController.php`
- Create: `platform/apps/api/database/migrations/*_create_users_table.php`
- Create: `platform/apps/api/tests/Feature/Identity/AuthenticationTest.php`
- Create: `platform/apps/web/app/(auth)/login/page.tsx`
- Create: `platform/apps/web/features/auth/`

**Интерфейси:**
- Produces: `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me`; `User` UUID, locale, timezone, MFA status.

- [ ] **Стъпка 1: Напишете failing auth тестове**

Покрийте: успешен вход; generic грешка при грешни credentials; rate limit; session regeneration; inactive user; `GET /me`; cookie flags; липса на password/token в logs.

- [ ] **Стъпка 2: Потвърдете failing резултата**

Run: `cd platform/apps/api && php artisan test tests/Feature/Identity/AuthenticationTest.php`

Expected: FAIL с липсващи routes/actions.

- [ ] **Стъпка 3: Имплементирайте identity модела и session auth**

Използвайте Argon2id, Laravel Sanctum за first-party SPA и UUID идентификатори. Не добавяйте social login в този етап.

- [ ] **Стъпка 4: Изградете достъпна login страница**

Формата има видими labels, keyboard focus, generic error, loading state и Bulgarian copy.

- [ ] **Стъпка 5: Проверете API и UI**

Run: `cd platform && composer --working-dir=apps/api test && pnpm --dir apps/web test && pnpm --dir apps/web test:e2e --grep "login"`

Expected: PASS.

- [ ] **Стъпка 6: Commit**

`git commit -m "feat: add secure user authentication"`

### Задача 4: Организации, членства и tenant context

**Файлове:**
- Create: `platform/apps/api/app/Domain/Organizations/Models/Organization.php`
- Create: `platform/apps/api/app/Domain/Organizations/Models/OrganizationMembership.php`
- Create: `platform/apps/api/app/Domain/Organizations/Tenancy/TenantContext.php`
- Create: `platform/apps/api/app/Http/Middleware/ResolveTenant.php`
- Create: `platform/apps/api/database/migrations/*_create_organizations_table.php`
- Create: `platform/apps/api/database/migrations/*_create_organization_memberships_table.php`
- Create: `platform/apps/api/database/migrations/*_enable_tenant_rls.php`
- Create: `platform/apps/api/tests/Feature/Organizations/TenantIsolationTest.php`

**Интерфейси:**
- Consumes: authenticated `User` от Задача 3.
- Produces: `TenantContext::organizationId(): string`, `GET /api/v1/organizations`, `POST /api/v1/context/organization`.

- [ ] **Стъпка 1: Напишете failing tenant isolation тестове**

Създайте две организации и потребител с членство само в първата. Проверете application request и директна repository заявка към чуждата организация. Покрийте потребител с две членства и липсващ/невалиден tenant header/context.

- [ ] **Стъпка 2: Потвърдете failing резултата**

Run: `cd platform/apps/api && php artisan test tests/Feature/Organizations/TenantIsolationTest.php`

Expected: FAIL, включително демонстриран cross-tenant read преди защитата.

- [ ] **Стъпка 3: Имплементирайте Organization, Membership и ResolveTenant**

Tenant се избира само измежду активните членства. Не се доверявайте на `organization_id` от request body.

- [ ] **Стъпка 4: Добавете PostgreSQL RLS**

Политиката използва transaction-scoped tenant setting; platform-operator bypass има отделна DB роля и не се използва от обикновени requests.

- [ ] **Стъпка 5: Стартирайте isolation suite**

Run: `cd platform/apps/api && php artisan test --group=tenant-isolation`

Expected: PASS за application и database layer.

- [ ] **Стъпка 6: Commit**

`git commit -m "feat: enforce organization tenant isolation"`

### Задача 5: Роли, permissions и покани

**Файлове:**
- Create: `platform/apps/api/app/Domain/Organizations/Enums/OrganizationRole.php`
- Create: `platform/apps/api/app/Domain/Organizations/Models/Invitation.php`
- Create: `platform/apps/api/app/Domain/Organizations/Actions/InviteMember.php`
- Create: `platform/apps/api/app/Domain/Organizations/Policies/OrganizationPolicy.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/InvitationController.php`
- Create: `platform/apps/api/tests/Feature/Organizations/InvitationTest.php`
- Create: `platform/apps/api/tests/Feature/Organizations/AuthorizationMatrixTest.php`

**Интерфейси:**
- Consumes: `TenantContext`.
- Produces: roles `platform_admin`, `organization_owner`, `organization_admin`, `coordinator`, `instructor`, `finance`, `viewer`; invitation endpoints and one-time hashed token.

- [ ] **Стъпка 1: Напишете authorization matrix и invitation тестовете**

Покрийте всяка роля срещу create/update/invite/finance/view. Покрийте duplicate invite, expiry, replay, wrong email и tenant mismatch.

- [ ] **Стъпка 2: Потвърдете failing резултата**

Run: `cd platform/apps/api && php artisan test tests/Feature/Organizations/AuthorizationMatrixTest.php tests/Feature/Organizations/InvitationTest.php`

Expected: FAIL.

- [ ] **Стъпка 3: Имплементирайте enum, policies и invitation lifecycle**

Token-ът се пази само хеширан, изтича след конфигурируем период и приемането е idempotent в DB transaction.

- [ ] **Стъпка 4: Проверете matrix и поканите**

Run: `cd platform/apps/api && php artisan test --group=authorization`

Expected: PASS.

- [ ] **Стъпка 5: Commit**

`git commit -m "feat: add organization roles and invitations"`

### Задача 6: Неизменяем audit trail

**Файлове:**
- Create: `platform/apps/api/app/Domain/Audit/Models/AuditEvent.php`
- Create: `platform/apps/api/app/Domain/Audit/AuditRecorder.php`
- Create: `platform/apps/api/database/migrations/*_create_audit_events_table.php`
- Create: `platform/apps/api/tests/Feature/Audit/AuditTrailTest.php`

**Интерфейси:**
- Consumes: authenticated user, tenant context и correlation ID.
- Produces: `AuditRecorder::record(AuditAction $action, AuditableReference $subject, array $safeMetadata): void`.

- [ ] **Стъпка 1: Напишете failing audit тестове**

Проверете login, tenant switch, invite, role change и бъдещ submit. Събитието съдържа actor, tenant, action, subject, request/correlation ID и време; няма secret или пълен sensitive payload. Update/delete на audit ред се отказва.

- [ ] **Стъпка 2: Потвърдете failing резултата**

Run: `cd platform/apps/api && php artisan test tests/Feature/Audit/AuditTrailTest.php`

Expected: FAIL.

- [ ] **Стъпка 3: Имплементирайте append-only recorder**

Записът участва в същата transaction, когато бизнес действието трябва да бъде атомарно.

- [ ] **Стъпка 4: Проверете audit suite**

Run: `cd platform/apps/api && php artisan test --group=audit`

Expected: PASS.

- [ ] **Стъпка 5: Commit**

`git commit -m "feat: add immutable audit trail"`

### Задача 7: Организационен onboarding и операторско одобрение

**Файлове:**
- Create: `platform/apps/api/app/Domain/Organizations/Enums/OrganizationStatus.php`
- Create: `platform/apps/api/app/Domain/Organizations/Actions/SubmitOrganizationApplication.php`
- Create: `platform/apps/api/app/Domain/Organizations/Actions/ReviewOrganizationApplication.php`
- Create: `platform/apps/api/app/Http/Controllers/Api/V1/OrganizationApplicationController.php`
- Create: `platform/apps/api/tests/Feature/Organizations/OrganizationOnboardingTest.php`
- Create: `platform/apps/web/features/organizations/application/`
- Create: `platform/apps/web/app/(workspace)/operator/organizations/page.tsx`

**Интерфейси:**
- Produces: lifecycle `applicant`, `review`, `approved`, `active`, `restricted`, `suspended`; submit/review endpoints с optimistic locking.

- [ ] **Стъпка 1: Напишете failing lifecycle тестове**

Проверете допустимите/недопустимите преходи, повторен submit, конкурентен review, partner self-approval и audit events.

- [ ] **Стъпка 2: Потвърдете failing резултата**

Run: `cd platform/apps/api && php artisan test tests/Feature/Organizations/OrganizationOnboardingTest.php`

Expected: FAIL.

- [ ] **Стъпка 3: Имплементирайте state transitions и version check**

Само platform operator може да одобрява/restrict/suspend. Партньорът вижда причина и следващо действие, но не вътрешни risk бележки.

- [ ] **Стъпка 4: Изградете applicant и operator screens**

Използвайте status text + icon, action inbox и видим активен tenant/role.

- [ ] **Стъпка 5: Проверете API, accessibility и E2E flow**

Run: `cd platform && composer --working-dir=apps/api test && pnpm --dir apps/web test && pnpm --dir apps/web test:e2e --grep "organization approval"`

Expected: PASS.

- [ ] **Стъпка 6: Commit**

`git commit -m "feat: add controlled organization onboarding"`

### Задача 8: Първа дейност и workflow за одобрение

**Файлове:**
- Create: `platform/apps/api/app/Domain/Catalog/Models/Service.php`
- Create: `platform/apps/api/app/Domain/Catalog/Models/ServiceVersion.php`
- Create: `platform/apps/api/app/Domain/Catalog/Enums/PublicationStatus.php`
- Create: `platform/apps/api/app/Domain/Catalog/Enums/Visibility.php`
- Create: `platform/apps/api/app/Domain/Catalog/Actions/SubmitServiceForReview.php`
- Create: `platform/apps/api/app/Domain/Catalog/Actions/ReviewService.php`
- Create: `platform/apps/api/tests/Feature/Catalog/ServiceWorkflowTest.php`
- Create: `platform/apps/web/features/catalog/service-editor/`

**Интерфейси:**
- Consumes: tenant, role policies, organization `active` status и audit recorder.
- Produces: lifecycle `draft`, `submitted`, `changes_requested`, `approved`, `published`, `archived`; visibility `internal`, `invite_only`, `organization_public`, `marketplace_public`.

- [ ] **Стъпка 1: Напишете failing workflow и versioning тестове**

Покрийте draft ownership, required fields, inactive partner, forbidden publish, foreign tenant, duplicate submit и редакция на approved/published service чрез нова version.

- [ ] **Стъпка 2: Потвърдете failing резултата**

Run: `cd platform/apps/api && php artisan test tests/Feature/Catalog/ServiceWorkflowTest.php`

Expected: FAIL.

- [ ] **Стъпка 3: Имплементирайте Service aggregate и review actions**

Публикуваната версия е immutable. Прегледът работи върху конкретна version; съществена промяна не засяга закупени условия.

- [ ] **Стъпка 4: Изградете guided editor**

Стъпки: основна информация → възраст/категория → локация → видимост → preview → submit. Добавете autosave indicator и server validation summary.

- [ ] **Стъпка 5: Проверете пълния вертикален поток**

Run: `cd platform && composer --working-dir=apps/api test && pnpm --dir apps/web test:e2e --grep "submit first activity"`

Expected: оператор одобрява организация; партньор създава и изпраща дейност; партньорът не може да я публикува; операторът връща/одобрява; всичко е одитирано.

- [ ] **Стъпка 6: Commit**

`git commit -m "feat: add versioned activity approval workflow"`

### Задача 9: OpenAPI договор и генериран web клиент

**Файлове:**
- Create: `platform/docs/api/openapi.yaml`
- Create: `platform/packages/api-client/src/generated/`
- Create: `platform/apps/api/tests/Feature/Api/OpenApiContractTest.php`
- Modify: `platform/apps/web/lib/api/`

**Интерфейси:**
- Consumes: auth, organizations, invitations, onboarding и catalog endpoints.
- Produces: versioned OpenAPI 3.1 contract и типизиран TypeScript client.

- [ ] **Стъпка 1: Добавете failing contract test**

Тестът сравнява реалните response shapes/status codes с OpenAPI examples и отказва undocumented endpoint под `/api/v1`.

- [ ] **Стъпка 2: Потвърдете failing резултата**

Run: `cd platform && pnpm api:contract:test`

Expected: FAIL.

- [ ] **Стъпка 3: Опишете API и генерирайте клиента**

Генерираният код не се редактира ръчно. Errors имат стабилни `code`, `message`, `field_errors`, `correlation_id`.

- [ ] **Стъпка 4: Заменете ръчните web fetch wrappers**

Всички първи екрани използват генерирания клиент.

- [ ] **Стъпка 5: Проверете contract drift**

Run: `cd platform && pnpm api:generate && git diff --exit-code packages/api-client/src/generated && pnpm verify`

Expected: PASS и няма неприбран generated diff.

- [ ] **Стъпка 6: Commit**

`git commit -m "feat: publish v1 API contract and typed client"`

### Задача 10: Staging deployment и оперативна готовност

**Файлове:**
- Create: `platform/infra/digitalocean/app.yaml`
- Create: `platform/apps/api/Dockerfile`
- Create: `platform/apps/web/Dockerfile`
- Create: `platform/docs/operations/deploy.md`
- Create: `platform/docs/operations/backup-restore.md`
- Create: `platform/docs/operations/incident-basics.md`
- Create: `platform/tests/smoke/staging.spec.ts`

**Интерфейси:**
- Consumes: deployable API/web images, managed PostgreSQL и Redis.
- Produces: staging URL, health/readiness checks, structured logs, error tracking и restore runbook.

- [ ] **Стъпка 1: Напишете staging smoke тест**

Покрийте health, login page, TLS, security headers, API CORS allowlist и липса на stack trace при 500.

- [ ] **Стъпка 2: Build-нете production images локално**

Run: `cd platform && docker build -f apps/api/Dockerfile . && docker build -f apps/web/Dockerfile .`

Expected: двата build-а завършват успешно и контейнерите минават health checks.

- [ ] **Стъпка 3: Подгответе DigitalOcean App Platform manifest**

Използвайте Frankfurt, managed production-grade database за staging rehearsal, encrypted secrets и least-privilege trusted sources. Не provisioning-вайте платени ресурси без изрично одобрение на собственика.

- [ ] **Стъпка 4: Опишете deploy, rollback и restore**

Runbook-ът съдържа отговорник, стъпки, проверка, критерий за rollback и доказателство за restore.

- [ ] **Стъпка 5: След одобрение deploy-нете staging и изпълнете smoke suite**

Run: `cd platform && STAGING_BASE_URL=<approved-url> pnpm test:staging`

Expected: PASS; tenant isolation suite е изпълнена и срещу staging database.

- [ ] **Стъпка 6: Commit**

`git commit -m "ops: add reproducible staging deployment"`

## 4. Паралелни продуктови решения през първите три седмици

Тези резултати не блокират локалното scaffold-ване, но блокират production финансовата реализация:

- [ ] Кратък документ от платежен доставчик/юрист: кой е merchant of record и кой държи средствата.
- [ ] Счетоводна схема за родител → оператор → партньор и училище → оператор.
- [ ] Начална RACI карта controller/processor за marketplace и SaaS режимите.
- [ ] Списък с реално необходимите данни и срокове за съхранение.
- [ ] Пилотен списък: Progressive Academy + 1 училище + 1 външен клуб.
- [ ] Решение за име и основен домейн на неутралния marketplace.
- [ ] Интервю/преглед с по един родител, училищен координатор, партньор и преподавател върху първите wireframes.

## 5. Exit criteria след седмица 3

- локалната среда се стартира с една документирана команда;
- CI изпълнява lint, static analysis, API, UI, E2E и security dependency checks;
- platform operator може да прегледа и одобри организация;
- одобрен partner admin може да покани член и да създаде дейност;
- дейността не може да бъде публикувана без operator review;
- cross-tenant тестовете доказват application и database изолация;
- всички чувствителни действия имат append-only audit event;
- OpenAPI договорът и TypeScript клиентът са синхронизирани;
- staging конфигурацията е възпроизводима, но платени ресурси се създават само след изрично одобрение;
- има демонстрация с изцяло синтетични данни и списък с наученото за следващия план.

## 6. Самопроверка на плана

- Обхватът умишлено не включва семейства, резервации, плащания и присъствия; те са отделни вертикални планове.
- Tenant isolation, idempotency, concurrent review и immutable publication са покрити с конкретни тестове.
- Имената `TenantContext`, `OrganizationMembership`, `ServiceVersion`, status и visibility enums са последователни във всички задачи.
- Планът не provisioning-ва платена инфраструктура и не избира PSP без ново изрично решение.
- Първият резултат е демонстрируем продукт, а не само инфраструктура.
