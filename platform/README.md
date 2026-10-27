# Progressive Activities Platform

## Local development

Requirements: Docker Desktop and pnpm 11.

```bash
docker compose up --build
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --class='Database\Seeders\LocalDemoSeeder' --force
```

- Web: http://localhost:3000
- API: http://localhost:8080/api/v1/health
- Mailpit: http://localhost:8025
- Демо вход: http://localhost:3000/login с локалните `DEMO_USER_EMAIL` и `DEMO_USER_PASSWORD` от `compose.yaml`
- Администрация на дейностите: http://localhost:3000/dashboard/activities
- Публичен демо каталог: http://localhost:3000/o/progressive-academy-demo
- Публична страница „Шахмат“: http://localhost:3000/o/progressive-academy-demo/activities/shahmat

All local data is synthetic. Never import production or Fitsys exports.
