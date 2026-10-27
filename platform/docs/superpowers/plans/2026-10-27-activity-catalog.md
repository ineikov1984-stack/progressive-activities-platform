# План за реализация на първия организационен каталог

> **За агентни изпълнители:** ЗАДЪЛЖИТЕЛНО ДОПЪЛНИТЕЛНО УМЕНИЕ: използвайте `superpowers:subagent-driven-development` (препоръчително) или `superpowers:executing-plans`, за да изпълнявате плана задача по задача. Стъпките използват отметки (`- [ ]`) за проследяване.

**Цел:** Активна организация да създава дейности, места, преподаватели, групи, повтарящи се графици и цени в евро, да ги публикува в собствен каталог и родител да вижда каталога и подробната страница без реално записване.

**Архитектура:** Laravel API съхранява tenant-защитените записи `Activity → Offering → Group → Session`, многократно използваемите места и преподаватели и вътрешното публикуване. Next.js предоставя отделно организационно работно пространство и публични страници `/o/[organizationSlug]` и `/o/[organizationSlug]/activities/[activitySlug]`. Първият вертикален етап не включва глобална модерация, плащане, реални записвания или AI услуга.

**Технологии:** PHP 8.3, Laravel 13.17, Sanctum, PostgreSQL 17 с RLS, PHPUnit 12; Next.js 16.4, React 19.2, TypeScript 5.9, Vitest 4, Playwright 1.56, CSS Modules; Docker Compose.

**Спецификация:** `docs/superpowers/specs/2026-10-27-activity-catalog-design.md`

## Общи ограничения

- Работната валута е EUR; сумите се записват като цели евроцентове, никога като плаваща запетая.
- Връзките между записите използват UUID; публичните адреси използват уникални устойчиви slug стойности.
- Всички частни заявки минават през `tenant` и `organization.access:operational.write` или `operational.read` според действието.
- Организация в статус `restricted` има само четене; само `active` може да създава, редактира и публикува.
- Публичните отговори съдържат само публикувани полета и никога вътрешни бележки, права или tenant метаданни.
- Запазването на чернова е позволено при конфликт; публикуването е забранено при застъпване на преподавател или самостоятелна зала.
- Първият етап няма реални записвания, плащания, маркетплейс модерация, AI генериране, custom domains или embeds.
- Българският е първият език на интерфейса; кодовите стойности и API имената остават на английски.
- Съществуващата потребителска промяна в `apps/web/src/app/(auth)/login/page.tsx` не се променя и не се включва в commit-и.

## Фокус при прегледа

- Чужд `organization_id` в payload или URL не трябва да дава достъп до чужд tenant; Task 1 и Task 2 добавят отрицателни API/RLS тестове.
- Невалиден EUR размер (отрицателен, дробен или неподходяща единица) трябва да върне 422; Task 5 го фиксира с тест.
- График през смяна на часа, изключена дата или период през седмица не трябва да дублира/измества занятия; Task 4 добавя точни тестове.
- Публичен slug за непубликувана/архивирана дейност трябва да връща 404; Task 7 го фиксира с API и browser тест.
- Публикуване при конфликт или непълна основна информация трябва да бъде атомично отказано без частично сменен статус; Task 5 го фиксира с транзакционен тест.

---

### Задача 1: Tenant-защитена основа на каталога

**Файлове:**
- Създаване: `apps/api/database/migrations/2026_10_27_001000_create_catalog_tables.php`
- Създаване: `apps/api/database/migrations/2026_10_27_001100_enable_catalog_rls.php`
- Създаване: `apps/api/app/Domain/Catalog/Enums/ActivityStatus.php`
- Създаване: `apps/api/app/Domain/Catalog/Enums/LocationType.php`
- Създаване: `apps/api/app/Domain/Catalog/Enums/SpaceUsage.php`
- Създаване: `apps/api/app/Domain/Catalog/Models/Activity.php`
- Създаване: `apps/api/app/Domain/Catalog/Models/Offering.php`
- Създаване: `apps/api/app/Domain/Catalog/Models/Group.php`
- Създаване: `apps/api/app/Domain/Catalog/Models/Session.php`
- Създаване: `apps/api/app/Domain/Catalog/Models/Location.php`
- Създаване: `apps/api/app/Domain/Catalog/Models/InstructorProfile.php`
- Създаване: `apps/api/app/Domain/Catalog/Models/PriceOption.php`
- Създаване: `apps/api/app/Domain/Catalog/Models/OrganizationCatalogProfile.php`
- Създаване: `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php`

**Интерфейси:**
- Използва: `TenantScope`, `TenantContext`, UUID моделите и PostgreSQL `app.current_organization_id` от съществуващия Organizations домейн.
- Предоставя: таблици и Eloquent отношения за `Activity`, `Offering`, `Group`, `Session`, `Location`, `InstructorProfile`, `PriceOption`, `OrganizationCatalogProfile`; enum стойности `draft|published|archived`, `physical|online|tbc`, `exclusive|shared`.

- [ ] **Стъпка 1: Напишете неуспешните тестове за изолация**

Добавете тестове `test_catalog_models_are_scoped_to_selected_organization`, `test_request_role_cannot_read_foreign_catalog_rows` и `test_catalog_payload_cannot_override_organization_id`; очаквайте чуждите UUID да липсват от заявките и приложната PostgreSQL роля да не ги вижда.

- [ ] **Стъпка 2: Потвърдете, че тестът се проваля**

Изпълнете: `docker compose run --rm --no-deps api php artisan test tests/Feature/Catalog/CatalogTenantIsolationTest.php`

Очакване: FAIL, защото таблиците и моделите още не съществуват.

- [ ] **Стъпка 3: Създайте минималната схема и моделите**

Миграцията създава UUID primary keys, задължителен `organization_id`, version поле за редакции, timestamps и foreign keys. `activities` пази основното съдържание и slug; `organization_catalog_profiles` пази публично име, intro, logo path, accent color и `is_published`; останалите таблици пазят само отговорността от името си. Всеки частен organization-owned модел добавя `TenantScope('organization_id')`.

- [ ] **Стъпка 4: Добавете RLS политики**

Активирайте и форсирайте RLS за частните catalog таблици с `organization_id = current_setting('app.current_organization_id', true)::uuid`; осигурете grants за приложната роля чрез същия модел като `2026_10_27_000300_enable_tenant_rls.php`. За `organization_catalog_profiles` добавете отделна SELECT policy, която позволява четене без tenant само когато `is_published = true`; writes остават tenant-защитени. Публичният resolver използва този профил, получава `organization_id`, задава `TenantContext`, след което чете останалите публикувани данни през нормалната RLS защита.

- [ ] **Стъпка 5: Изпълнете тестовете**

Изпълнете същия test файл; очакване: PASS и PostgreSQL проверките да не са skipped в Docker.

- [ ] **Стъпка 6: Commit**

```bash
git add apps/api/app/Domain/Catalog apps/api/database/migrations/2026_10_27_001000_create_catalog_tables.php apps/api/database/migrations/2026_10_27_001100_enable_catalog_rls.php apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php
git commit -m "feat: add tenant-safe catalog foundation"
```

### Задача 2: Места и преподаватели

**Файлове:**
- Създаване: `apps/api/app/Domain/Catalog/Policies/CatalogPolicy.php`
- Създаване: `apps/api/app/Http/Controllers/Api/V1/LocationController.php`
- Създаване: `apps/api/app/Http/Controllers/Api/V1/InstructorProfileController.php`
- Създаване: `apps/api/app/Http/Controllers/Api/V1/OrganizationCatalogProfileController.php`
- Промяна: `apps/api/app/Providers/AppServiceProvider.php`
- Промяна: `apps/api/app/Domain/Organizations/Enums/OrganizationRole.php`
- Промяна: `apps/api/routes/api.php`
- Създаване: `apps/api/tests/Feature/Catalog/CatalogResourcesTest.php`

**Интерфейси:**
- Използва: `Location`, `InstructorProfile`, активния tenant и organization membership role.
- Предоставя: `GET|PATCH /api/v1/catalog/profile`, `GET|POST /api/v1/catalog/locations`, `PATCH /api/v1/catalog/locations/{location}`, `GET|POST /api/v1/catalog/instructors`, `PATCH /api/v1/catalog/instructors/{instructor}`; способности `catalog.view`, `catalog.manage`, `catalog.publish`.

- [ ] **Стъпка 1: Напишете API тестовете**

Покрийте: owner/admin/coordinator могат да управляват; instructor/viewer могат само да четат; restricted организация не може да пише; чужд UUID връща 404; physical място изисква адрес, online не изисква; публичното име на преподавател е задължително; catalog profile приема валиден hex accent color и не може да стане публичен без публично име.

- [ ] **Стъпка 2: Потвърдете провала**

Изпълнете: `docker compose run --rm --no-deps api php artisan test tests/Feature/Catalog/CatalogResourcesTest.php`

Очакване: FAIL с липсващи routes/controllers.

- [ ] **Стъпка 3: Добавете catalog способностите и policy**

`CatalogPolicy` предоставя `view(User, Organization)`, `manage(User, Organization)` и `publish(User, Organization)`; owner/admin имат трите, coordinator има view/manage, instructor/viewer имат view, останалите нямат catalog управление.

- [ ] **Стъпка 4: Реализирайте контролерите и валидацията**

Приемайте само публичните полета от спецификацията; не приемайте `organization_id`. Връщайте `{ profile: ... }`, `{ locations: [...] }`, `{ location: ... }`, `{ instructors: [...] }`, `{ instructor: ... }` с 201 при създаване.

- [ ] **Стъпка 5: Регистрирайте защитените routes**

Всички маршрути са под `auth:sanctum`, `tenant`; GET използва `organization.access:operational.read`, POST/PATCH използва `organization.access:operational.write` и policy.

- [ ] **Стъпка 6: Изпълнете тестовете и commit**

Очакване: `CatalogResourcesTest` PASS.

```bash
git add apps/api/app/Domain/Catalog/Policies apps/api/app/Http/Controllers/Api/V1/LocationController.php apps/api/app/Http/Controllers/Api/V1/InstructorProfileController.php apps/api/app/Http/Controllers/Api/V1/OrganizationCatalogProfileController.php apps/api/app/Providers/AppServiceProvider.php apps/api/app/Domain/Organizations/Enums/OrganizationRole.php apps/api/routes/api.php apps/api/tests/Feature/Catalog/CatalogResourcesTest.php
git commit -m "feat: manage catalog locations and instructors"
```

### Задача 3: Чернови на дейности, предлагания и богато съдържание

**Файлове:**
- Създаване: `apps/api/app/Domain/Catalog/Actions/CreateActivityDraft.php`
- Създаване: `apps/api/app/Domain/Catalog/Actions/UpdateActivityDraft.php`
- Създаване: `apps/api/app/Http/Controllers/Api/V1/ActivityController.php`
- Създаване: `apps/api/app/Http/Controllers/Api/V1/CatalogMediaController.php`
- Промяна: `apps/api/routes/api.php`
- Създаване: `apps/api/tests/Feature/Catalog/ActivityDraftTest.php`

**Интерфейси:**
- Използва: catalog policy и моделите от Tasks 1–2.
- Предоставя: `GET /api/v1/catalog/activities`, `POST /api/v1/catalog/activities`, `GET /api/v1/catalog/activities/{activity}`, `PATCH /api/v1/catalog/activities/{activity}`, `POST /api/v1/catalog/media`; payload с `version`, core полета, optional `sections` и offering terms.

- [ ] **Стъпка 1: Напишете тестове за чернова и optimistic locking**

Тестовете доказват: минимална чернова изисква title/category/summary/age label; българско заглавие получава уникален латински slug; optional sections могат да липсват; update с актуална version увеличава версията; остаряла version връща 409; чужда дейност връща 404; media endpoint приема само JPEG/PNG/WebP до 5 MB, отказва SVG/изпълними файлове и връща tenant-owned opaque path.

- [ ] **Стъпка 2: Потвърдете провала**

Изпълнете: `docker compose run --rm --no-deps api php artisan test tests/Feature/Catalog/ActivityDraftTest.php`

- [ ] **Стъпка 3: Реализирайте actions**

`CreateActivityDraft::handle(User $actor, Organization $organization, array $payload): Activity` и `UpdateActivityDraft::handle(User $actor, Activity $activity, int $expectedVersion, array $payload): Activity`; записват Activity и неговото първо Offering в транзакция и никога не приемат tenant от payload.

- [ ] **Стъпка 4: Реализирайте controller представянето**

JSON съдържа `id`, `slug`, `status`, `version`, `title`, `summary`, `category`, `age_label`, изображения, curated sections и offering terms. Полетата за бъдеща marketplace модерация не се включват.

- [ ] **Стъпка 5: Реализирайте безопасното качване на изображения**

`CatalogMediaController::store` записва файловете чрез Laravel Storage с генерирано име, проверено MIME съдържание и tenant/activity metadata; не приема потребителско име като storage path. Черновите изображения се обслужват само през защитен endpoint, а публичният controller предоставя само изображения, свързани с публикувани profile/activity записи.

- [ ] **Стъпка 6: Изпълнете тестовете и commit**

Очакване: `ActivityDraftTest` PASS.

```bash
git add apps/api/app/Domain/Catalog/Actions apps/api/app/Http/Controllers/Api/V1/ActivityController.php apps/api/app/Http/Controllers/Api/V1/CatalogMediaController.php apps/api/routes/api.php apps/api/tests/Feature/Catalog/ActivityDraftTest.php
git commit -m "feat: add catalog activity drafts"
```

### Задача 4: Групи, повтарящ график, занятия и конфликти

**Файлове:**
- Създаване: `apps/api/app/Domain/Catalog/ValueObjects/SchedulePattern.php`
- Създаване: `apps/api/app/Domain/Catalog/Services/SessionScheduleGenerator.php`
- Създаване: `apps/api/app/Domain/Catalog/Services/ScheduleConflictDetector.php`
- Създаване: `apps/api/app/Domain/Catalog/Actions/SaveGroupSchedule.php`
- Създаване: `apps/api/app/Http/Controllers/Api/V1/ActivityGroupController.php`
- Промяна: `apps/api/routes/api.php`
- Създаване: `apps/api/tests/Unit/Catalog/SessionScheduleGeneratorTest.php`
- Създаване: `apps/api/tests/Feature/Catalog/ActivityGroupTest.php`

**Интерфейси:**
- Използва: Offering, Group, Session, Location, InstructorProfile.
- Предоставя: `SessionScheduleGenerator::generate(SchedulePattern $pattern, array $excludedDates, string $timezone): array`; `ScheduleConflictDetector::forGroup(Group $group): array`; `GET|POST /api/v1/catalog/activities/{activity}/groups`, `PATCH /api/v1/catalog/groups/{group}`.

- [ ] **Стъпка 1: Напишете unit тестовете за генератора**

Покрийте: вторник+четвъртък с различни часове; честота през седмица; начална/крайна дата; изключена дата; ръчно добавена дата; `Europe/Sofia` при преминаване лято/зима без промяна на местния начален час.

- [ ] **Стъпка 2: Потвърдете unit провала**

Изпълнете: `docker compose run --rm --no-deps api php artisan test tests/Unit/Catalog/SessionScheduleGeneratorTest.php`

- [ ] **Стъпка 3: Реализирайте value object и генератора**

`SchedulePattern` приема ISO дати, `weekly|biweekly`, и списък от `{weekday: 1..7, starts_at: HH:MM, duration_minutes: int}`. Генераторът връща локални начални/крайни моменти и не дублира изключени/добавени дати.

- [ ] **Стъпка 4: Напишете feature тестовете**

Покрийте създаване на група, min/max капацитет, много преподаватели, default location, session override, предупреждение за shared space и блокиращи конфликти за instructor/exclusive location. Потвърдете, че конфликтна чернова се пази.

- [ ] **Стъпка 5: Реализирайте записването на група и conflict detector**

`SaveGroupSchedule::handle(Activity $activity, array $payload): Group` записва група, patterns, instructor pivot и sessions атомично. `ScheduleConflictDetector` проверява само активния tenant и връща структурирани `{type, session_id, conflicting_session_id, blocking, message}`.

- [ ] **Стъпка 6: Изпълнете unit и feature тестовете и commit**

Очакване: двата test файла PASS.

```bash
git add apps/api/app/Domain/Catalog apps/api/app/Http/Controllers/Api/V1/ActivityGroupController.php apps/api/routes/api.php apps/api/tests/Unit/Catalog/SessionScheduleGeneratorTest.php apps/api/tests/Feature/Catalog/ActivityGroupTest.php
git commit -m "feat: add recurring activity groups and sessions"
```

### Задача 5: Цени в евро и вътрешно публикуване

**Файлове:**
- Създаване: `apps/api/app/Domain/Catalog/Enums/EnrollmentMode.php`
- Създаване: `apps/api/app/Domain/Catalog/Enums/PriceUnit.php`
- Създаване: `apps/api/app/Domain/Catalog/Actions/PublishActivityInternally.php`
- Създаване: `apps/api/app/Http/Controllers/Api/V1/ActivityPublicationController.php`
- Промяна: `apps/api/app/Domain/Audit/Enums/AuditAction.php`
- Промяна: `apps/api/routes/api.php`
- Създаване: `apps/api/tests/Feature/Catalog/ActivityPublicationTest.php`

**Интерфейси:**
- Използва: Activity/Offering/Group/Sessions, conflict detector, AuditRecorder.
- Предоставя: enrollment modes `trial|single|full_period|monthly|package`; price units `session|month|period|package`; `POST /api/v1/catalog/activities/{activity}/publish`; `POST|PATCH` price options през activity/group payloads.

- [ ] **Стъпка 1: Напишете тестовете за цени и публикация**

Покрийте: amount е положителен integer euro cents и currency е фиксирано `EUR`; отрицателно/дробно число или непозната unit връща 422; група може да има няколко enrollment modes; непълна дейност или липсваща група/цена/сесия връща 422; блокиращ конфликт връща 409; успешното публикуване сменя статуса атомично и записва audit event веднъж.

- [ ] **Стъпка 2: Потвърдете провала**

Изпълнете: `docker compose run --rm --no-deps api php artisan test tests/Feature/Catalog/ActivityPublicationTest.php`

- [ ] **Стъпка 3: Реализирайте enums и publish action**

`PublishActivityInternally::handle(User $actor, Activity $activity, int $expectedVersion): Activity` валидира core content, offering, group, price, generated sessions и blocking conflicts в една transaction; задава `published` и `published_at` само при пълен успех.

- [ ] **Стъпка 4: Реализирайте endpoint и audit действие**

Добавете `catalog.activity_published`; endpoint изисква `catalog.publish`, active organization и актуална version.

- [ ] **Стъпка 5: Изпълнете test файла и commit**

Очакване: PASS, включително че отхвърлена публикация остава draft.

```bash
git add apps/api/app/Domain/Catalog apps/api/app/Http/Controllers/Api/V1/ActivityPublicationController.php apps/api/app/Domain/Audit/Enums/AuditAction.php apps/api/routes/api.php apps/api/tests/Feature/Catalog/ActivityPublicationTest.php
git commit -m "feat: publish internally priced activities"
```

### Задача 6: Административен интерфейс за каталог

**Файлове:**
- Създаване: `apps/web/src/app/dashboard/activities/page.tsx`
- Създаване: `apps/web/src/app/dashboard/activities/[activityId]/page.tsx`
- Създаване: `apps/web/src/features/catalog/admin/api.ts`
- Създаване: `apps/web/src/features/catalog/admin/activity-list.tsx`
- Създаване: `apps/web/src/features/catalog/admin/activity-editor.tsx`
- Създаване: `apps/web/src/features/catalog/admin/activity-editor.module.css`
- Създаване: `apps/web/src/features/catalog/admin/presentation.ts`
- Промяна: `apps/web/src/features/dashboard/dashboard.tsx`
- Създаване: `apps/web/tests/catalog-admin-presentation.test.ts`
- Създаване: `apps/web/tests/catalog-admin-api.test.ts`

**Интерфейси:**
- Използва: частните API endpoints от Tasks 2–5 и съществуващия `/backend` proxy/CSRF модел.
- Предоставя: `/dashboard/activities` и `/dashboard/activities/[activityId]`; TypeScript types `ActivityDraft`, `Location`, `InstructorProfile`, `ActivityGroup`, `PriceOption`, `ScheduleConflict`.

- [ ] **Стъпка 1: Напишете Vitest тестовете**

Покрийте API error mapping, EUR formatter, status/availability labels, slug preview, optional section omission, schedule summary и представяне на blocking/warning conflicts.

- [ ] **Стъпка 2: Потвърдете провала**

Изпълнете: `docker compose run --rm --no-deps web pnpm test -- tests/catalog-admin-api.test.ts tests/catalog-admin-presentation.test.ts`

- [ ] **Стъпка 3: Реализирайте API клиента и presentation helpers**

Следвайте `features/dashboard/api.ts`: credentials include, JSON error message, CSRF за writes. EUR се форматира с `bg-BG`, без преобразуване от float.

- [ ] **Стъпка 4: Реализирайте списъка и редактора**

Списъкът показва draft/published и действия за нова/редакция. Редакторът има ясни секции: Настройки на каталога (публично име, лого, цвят), Основна информация, Богато съдържание с подсказки, Места, Преподаватели, Групи и график, Цени, Преглед и публикуване. Изображенията се качват през media endpoint с preview и достъпно описание. Незадължителните секции са сгъваеми; AI бутонът е видим само като бъдеща disabled възможност с обяснение, без фалшива услуга.

- [ ] **Стъпка 5: Свържете dashboard навигацията**

Заменете неактивния текст „Дейности“ с връзка `/dashboard/activities`, без да променяте останалото табло.

- [ ] **Стъпка 6: Изпълнете lint, typecheck и tests и commit**

Изпълнете: `docker compose run --rm --no-deps web pnpm lint && docker compose run --rm --no-deps web pnpm typecheck && docker compose run --rm --no-deps web pnpm test`

Очакване: всички PASS.

```bash
git add apps/web/src/app/dashboard/activities apps/web/src/features/catalog/admin apps/web/src/features/dashboard/dashboard.tsx apps/web/tests/catalog-admin-api.test.ts apps/web/tests/catalog-admin-presentation.test.ts
git commit -m "feat: add organization catalog workspace"
```

### Задача 7: Публичен организационен каталог и страница на дейността

**Файлове:**
- Създаване: `apps/api/app/Http/Controllers/Api/V1/PublicCatalogController.php`
- Промяна: `apps/api/routes/api.php`
- Създаване: `apps/api/tests/Feature/Catalog/PublicCatalogTest.php`
- Създаване: `apps/web/src/app/o/[organizationSlug]/page.tsx`
- Създаване: `apps/web/src/app/o/[organizationSlug]/activities/[activitySlug]/page.tsx`
- Създаване: `apps/web/src/features/catalog/public/api.ts`
- Създаване: `apps/web/src/features/catalog/public/catalog-page.tsx`
- Създаване: `apps/web/src/features/catalog/public/activity-page.tsx`
- Създаване: `apps/web/src/features/catalog/public/catalog.module.css`
- Създаване: `apps/web/tests/catalog-public-presentation.test.ts`

**Интерфейси:**
- Използва: публикуваните Activity/Offering/Group/Session/PriceOption данни.
- Предоставя: unauthenticated `GET /api/v1/public/organizations/{organizationSlug}/catalog` и `GET /api/v1/public/organizations/{organizationSlug}/activities/{activitySlug}`; Next routes `/o/...`.

- [ ] **Стъпка 1: Напишете публичните API тестове**

Покрийте: само active организация и published activity са видими; draft/archived/foreign slug връща 404; response съдържа само публичните полета; price label unit съвпада; availability се извежда от capacity placeholder без ръчен public label; navigation URL се показва само при physical location.

- [ ] **Стъпка 2: Потвърдете провала**

Изпълнете: `docker compose run --rm --no-deps api php artisan test tests/Feature/Catalog/PublicCatalogTest.php`

- [ ] **Стъпка 3: Реализирайте публичния controller**

Публичното четене първо намира публикуван `OrganizationCatalogProfile` по slug през ограничената публична RLS policy, задава неговия `organization_id` в `TenantContext`, след което намира activity с изрично ограничение `active`/`published` и зарежда само публичните отношения. Никога не използва tenant, подаден от клиента.

- [ ] **Стъпка 4: Напишете и изпълнете web presentation теста**

Тестовете фиксират Bulgarian price labels, availability, group schedule, optional section omission и navigation action. Първото изпълнение трябва да FAIL с липсващи helpers/components.

- [ ] **Стъпка 5: Реализирайте публичните Next страници**

Каталогът показва responsive cards от референцията. Activity page показва hero, practical summary, optional about/benefits/methodology, groups+prices, terms, instructors, gallery и FAQ; бутонът за записване е „Записването ще бъде добавено в следващ етап“ и не имитира успешно записване.

- [ ] **Стъпка 6: Изпълнете API и web тестовете и commit**

Очакване: `PublicCatalogTest` и `catalog-public-presentation.test.ts` PASS; web lint/typecheck PASS.

```bash
git add apps/api/app/Http/Controllers/Api/V1/PublicCatalogController.php apps/api/routes/api.php apps/api/tests/Feature/Catalog/PublicCatalogTest.php apps/web/src/app/o apps/web/src/features/catalog/public apps/web/tests/catalog-public-presentation.test.ts
git commit -m "feat: publish organization activity catalog"
```

### Задача 8: Демо данни и browser journey

**Файлове:**
- Промяна: `apps/api/database/seeders/LocalDemoSeeder.php`
- Промяна: `apps/api/tests/Feature/Demo/LocalDemoSeederTest.php`
- Създаване: `apps/web/tests/e2e/activity-catalog.spec.ts`
- Промяна: `README.md`

**Интерфейси:**
- Използва: всички API и web интерфейси от Tasks 1–7.
- Предоставя: повторяем demo dataset с поне една публикувана шах дейност, две групи, EUR prices, място, преподавател и sessions; browser journey за администрация и публичен каталог.

- [ ] **Стъпка 1: Разширете failing demo seeder теста**

Проверете idempotent seed, правилния tenant, една публикувана дейност, две групи, session count по графика, euro-cent amounts и липса на данни в production среда.

- [ ] **Стъпка 2: Реализирайте demo seed**

Използвайте устойчиви UUID за demo records и upsert във transaction. Съдържанието е на български и следва шахматната референция, без да копира лични данни или снимки от нея.

- [ ] **Стъпка 3: Изпълнете demo теста**

Изпълнете: `docker compose run --rm --no-deps api php artisan test tests/Feature/Demo/LocalDemoSeederTest.php`

Очакване: PASS при повторно пускане.

- [ ] **Стъпка 4: Напишете Playwright journey**

Тестът влиза с demo user, отваря „Дейности“, редактира чернова/проверява публикуваната дейност, отваря `/o/progressive-academy-demo`, избира шах и проверява цена, групи, преподавател, навигация и disabled enrollment explanation на desktop и mobile viewport.

- [ ] **Стъпка 5: Документирайте локалното преглеждане**

README описва само нужните стъпки за миграция/seed, вход, admin URL и public catalog URL, без credentials извън вече конфигурираната local demo среда.

- [ ] **Стъпка 6: Изпълнете e2e и commit**

Изпълнете: `docker compose run --rm --no-deps web pnpm test:e2e -- tests/e2e/activity-catalog.spec.ts`

Очакване: PASS.

```bash
git add apps/api/database/seeders/LocalDemoSeeder.php apps/api/tests/Feature/Demo/LocalDemoSeederTest.php apps/web/tests/e2e/activity-catalog.spec.ts README.md
git commit -m "test: add activity catalog demo journey"
```

### Задача 9: Пълна проверка на първия вертикален етап

**Файлове:**
- Промяна при нужда само от открит дефект: файловете, притежаващи дефекта, и съответният regression test.

**Интерфейси:**
- Използва: завършените Tasks 1–8.
- Предоставя: проверен build и работещи Docker services, без нова продуктова функционалност.

- [ ] **Стъпка 1: Изпълнете API проверката**

Изпълнете: `docker compose run --rm --no-deps api composer verify`

Очакване: Pint, PHPStan и всички API тестове PASS.

- [ ] **Стъпка 2: Изпълнете web проверката**

Изпълнете: `docker compose run --rm --no-deps web sh -lc 'pnpm lint && pnpm typecheck && pnpm test && pnpm build'`

Очакване: всички проверки и production build PASS.

- [ ] **Стъпка 3: Мигрирайте, seed-нете и пресъздайте услугите**

Изпълнете: `docker compose run --rm api php artisan migrate --force && docker compose run --rm api php artisan db:seed --class=LocalDemoSeeder --force && docker compose up -d --force-recreate api web`

Очакване: postgres, redis и api са `Healthy`, web е `Started`.

- [ ] **Стъпка 4: Изпълнете целия Playwright пакет**

Изпълнете: `docker compose run --rm --no-deps web pnpm test:e2e`

Очакване: всички journeys PASS.

- [ ] **Стъпка 5: Направете ръчна browser проверка**

Проверете desktop и mobile: `/dashboard/activities`, activity editor, `/o/progressive-academy-demo` и demo activity page. Потвърдете Bulgarian copy, responsive layout, EUR, keyboard focus, optional sections, navigation link и че няма фалшиво записване.

- [ ] **Стъпка 6: Проверете границите на промяната**

Изпълнете: `git status --short` и `git diff --check`.

Очакване: няма whitespace errors; потребителската login промяна и `.pnpm-store/` не са включени в catalog commit-и.

- [ ] **Стъпка 7: Commit само ако проверката е наложила regression fix**

```bash
git add <точните поправени файлове и regression tests>
git commit -m "fix: harden organization activity catalog"
```
