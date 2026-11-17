# PromptEdit Assessment

## Local setup

Requires PHP 8.5 with PostgreSQL support, Composer, Node.js, and Docker Compose.

```bash
docker compose up -d
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

PostgreSQL is available on port `54329`. On its first start, the database volume is initialized with the `promptedit` and `promptedit_test` databases.

## Local demo

```bash
php artisan db:seed --class=DemoSeeder
npm run build
php artisan serve
```

Log in as `demo@example.com` with `DEMO_PASSWORD` (default: `password`). The seeder only runs in the local environment and can be repeated safely. It adds a fake $5 purchase and one generation in each status, with matching holds, releases, and the documented succeeded fixture's exact charge. The sample image is a bundled SVG; no Pixelforge requests or generation jobs are sent by the seeder.

For offline review, the seeder stores the documented pricing fixture under `pixelforge.pricing.last_known` only when that key is missing. It serves as an offline fallback without overriding cached or freshly fetched live pricing from `/v1/pricing`. The pending, queued, and processing samples remain in their seeded states so every status can be inspected.
