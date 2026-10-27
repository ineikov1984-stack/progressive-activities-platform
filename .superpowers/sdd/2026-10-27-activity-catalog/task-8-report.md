# Task 8 report — локално демо и browser journey

## Реализирано

- `LocalDemoSeeder` създава повторяеми демо данни в transaction със стабилни UUID и upsert.
- Публикувана българска дейност „Шахмат“ с богато безопасно съдържание, публично място и навигация, преподавател, две групи, 14 занятия и EUR цени до евроцент.
- Seeder-ът продължава да отказва изпълнение извън `local` и `testing`.
- Playwright journey покрива входа, административния списък и редактор, публичния каталог и детайлната страница на desktop и mobile.
- Бъдещото записване е видимо, но изключено с ясно обяснение.
- README съдържа кратките локални стъпки и URL адреси, като използва само вече конфигурираните demo credentials.

## TDD и проверки

- RED: след rebuild разширеният seeder test се провали очаквано, защото липсваше `organization_catalog_profiles` запис.
- GREEN: `LocalDemoSeederTest` — 2 tests, 23 assertions.
- Targeted Playwright — 2 passed (desktop и mobile).
- Пълна API проверка в отделна чиста PostgreSQL test база — Pint PASS (126 files), PHPStan PASS (70/70), 135 tests / 847 assertions PASS.
- Web unit tests — 7 files / 31 tests PASS.
- Web lint — PASS.
- Web typecheck — PASS.
- Web production build — PASS.

## Бележка за средата

Споделената локална demo база вече съдържа нарочно публикувания demo каталог. Един стар isolation test брои глобално публичните профили и затова пълната API проверка бе изпълнена недеструктивно в отделна чиста база `progressive_verify`. Демото остана заредено и достъпно за преглед.
