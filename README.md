# PromptEdit Assessment

Laravel 13, Vue 3 and Inertia app for asynchronous image generation through the simulated [Pixelforge API](https://pixelforge.promptedit.dev/docs). Purchases and images are simulated; no real payments or AI generation occur.

Live app: <https://promptedit-assessment-production-zjutmh.laravel.cloud>. Deployment instructions: [docs/deployment.md](docs/deployment.md).

## Try it in 15 minutes

1. Register; you land on **Generate** straight away (no email verification).
2. Click **Buy $5** to add fake credits. Enter a prompt, choose size and quality, optionally add up to four reference image URLs, and review the estimated range before generating.
3. Watch the history update every two seconds while generations are active. Open a generation for its token usage, rates, exact provider cost and final price; open **Ledger** for credit movements.
4. Sign in with the operator account and open **Report** at `/admin/report`. The report compares successful generations with Pixelforge by UTC completion day and shows totals by user; regular accounts cannot access it.

Add these keywords to prompts to exercise specific paths:

| Keyword        | Provider behavior                                              |
| -------------- | -------------------------------------------------------------- |
| `[succeed]`    | Accepted without random generation failures                    |
| `[fail]`       | Accepted, then fails during processing; no charge              |
| `[slow]`       | Processes for 60–90 seconds instead of 5–30                    |
| `[duplicate]`  | Delivers each webhook twice                                    |
| `[overloaded]` | Rejects submission with HTTP 503; the app retries              |
| `[blocked]`    | Rejects submission with HTTP 400 for content policy; no charge |

## Exact billing

All monetary arithmetic uses integer nanodollars: **$1 = 1,000,000,000 nanodollars**. Provider cost is `Σ tokens × rate`, using the rates on the generation snapshot, not today's pricing; decimal rate strings are parsed exactly into nanodollars per token. A rate that cannot be represented in whole nanodollars per token is rejected rather than rounded.

The user price is **cost × 1.25, rounded UP to the next $0.0001**. In integers: `intdiv(cost * 5 + 399_999, 400_000) * 100_000`. For example, 25 text tokens at $5/million, 1,100 output tokens at $40/million, and 1,234 reasoning tokens at $12.50/million cost **59_550_000 nanodollars ($0.05955)**; the marked-up $0.0744375 rounds up to **$0.0745**. Failed generations have no charge.

Before submission, the server estimates a minimum and maximum using prompt length (Unicode characters / 4, with ±15% token variance), reference image tokens, fixed output tokens and the quality's reasoning range. A 100-character prompt, medium quality, square image and no references gives **$0.0583–$0.0865**. Pricing is cached for ten minutes with a last-known fallback; the maximum price is held in the same transaction that creates the generation, after locking the user's row and checking available credits.

The ledger is append-only in the billing flow:

| Entry          | Effect                                                              |
| -------------- | ------------------------------------------------------------------- |
| `purchase`     | Adds $5; a unique form idempotency key prevents duplicate purchases |
| `hold`         | Reserves the maximum estimate                                       |
| `hold_release` | Returns the full hold when generation finishes, including failures  |
| `charge`       | Debits the final price only on success                              |

A unique `(generation_id, type)` constraint prevents duplicate holds, releases or charges. **Available** is the sum of all ledger entries; **Reserved** is the outstanding holds net of releases. Success, hold release and charge commit together.

## Reliability decisions

- **Our own queue.** `SubmitGeneration` sends the local UUID as the stable `Idempotency-Key` and includes `generation_id` and `user_id` metadata. Retries reuse the key and parameters; metadata also lets a webhook locate the row before the submission response arrives.
- **Three-concurrent gate.** Submission waits five seconds when three locally accepted generations are queued or processing (counting acceptances within the last hour). This check is not an atomic account-wide semaphore, so simultaneous workers can race; Pixelforge's own concurrency limit and our 429 retries remain the backstop.
- **Retries and give-up rules.** Rate/concurrency 429 responses use `Retry-After` plus 0–2 seconds of jitter, capped at 900 seconds; network/invalid-payload/5xx failures back off exponentially up to 60 seconds. All provider submission attempts share a six-attempt ceiling, and pending submissions older than 15 minutes fail with the hold released; the worker retry deadline is fixed at 20 minutes from dispatch. Content-policy and daily-quota refusals fail immediately, while unexpected refusals are logged as provider errors.
- **Webhooks.** HMAC-SHA256 verifies the raw body with a constant-time comparison and a five-minute timestamp tolerance; event IDs deduplicate deliveries. Status ranks prevent backward transitions and final states stay final; event insertion and snapshot application share one transaction so a failed update can be retried.
- **Self-stopping sync chain.** `SyncGeneration` starts two minutes after acceptance, then waits 2, 5, 10 and 15 minutes between checks, repeating the 15-minute delay until final or 24 hours after acceptance. `routes/console.php` schedules nothing, so there is no recurring poll when idle and Cloud can scale to zero; a provider 404 also stops the chain with an error log.
- **Private image copies.** A separate job copies successful PNGs to private storage, with three attempts and 10/30-second backoff. Owners access copies through an authenticated, authorized image route; until a copy exists, an unexpired Pixelforge URL is the fallback.
- **Reconciliation.** `/admin/report` compares our successful generation counts and exact provider costs with `/v1/usage` by **UTC day of completion**, not creation. Usage is cached for five minutes, ranges are limited to 92 inclusive days, and unavailable provider data is shown explicitly rather than treated as a match.

### Managed queue limits

[Laravel Cloud managed queues](https://laravel.com/cloud/docs/queues) require Laravel **13.19+** and `aws/aws-sdk-php`; both requirements are satisfied. Cloud injects `QUEUE_CONNECTION=cloud`, and the framework registers the connection; there is no custom connection in this app. Use a **standard Flex** queue: standard queues allow delays up to **900 seconds**, FIFO queues do not support per-job delays, and Flex jobs may run at most **90 seconds**. Starter permits one queue and at most three workers.

| Job                   | Delays / retry backoff                                                                                                      | Per-execution timeout                         |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| `SubmitGeneration`    | Gate: 5s; 429: `Retry-After` + 0–2s, capped at 900s; transient errors: 2/4/8/16/32s before the sixth attempt, capped at 60s | 60s; provider HTTP timeout 15s                |
| `SyncGeneration`      | Initial 120s, then 120/300/600/900s, repeating 900s; three worker attempts per job                                          | 60s; provider HTTP timeout 15s                |
| `StoreGeneratedImage` | Three attempts, backoff 10/30s                                                                                              | 60s; download timeout 30s, connect timeout 5s |

The 15-minute submission age and 24-hour sync horizon span separate executions, not one long-running job. All job delays are at most 900 seconds and each execution timeout is below Flex's 90-second limit. Managed workers have ephemeral disks, so production images use an attached private bucket selected by `IMAGES_DISK`.

## Edge cases and known limits

- If rates change between estimate and settlement, the actual price can exceed the hold. The app charges it anyway and logs the overrun; available credits can become negative.
- If submission is abandoned after 15 minutes (or the attempt ceiling) but Pixelforge later completes it, the final local failure stays final. Pixelforge bills that success, we do not charge the user, and the completion day's report shows a mismatch (`✗`).
- Unknown asynchronous failure codes are logged and mapped to `internal_error`; unexpected submission refusals become `provider_error`. Neither charges the user.
- Pixelforge image URLs expire after 24 hours. If copying fails or the URL expires before copying, the UI shows **Image expired**; existing copies remain accessible to the owner.
- Webhook delivery is not guaranteed. Sync covers accepted generations, but stops after 24 hours, on provider 404, or if unexpected errors exhaust worker attempts; unresolved holds then require operator investigation.
- The local demo is synthetic and does not reconcile with live provider usage. Fake credit purchases are not a payment integration.
- Email verification is disabled so reviewers can use the app right after registering; credits are fake. The deployment configures no mail transport, so password-reset emails are not delivered.

## Local setup

Requires PHP 8.5 with `pdo_pgsql`, Composer, Node.js 22 and Docker Compose.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan migrate
npm run build
php artisan serve
```

Postgres 17 listens on `127.0.0.1:54329`. On first volume initialization, Docker creates `promptedit` and `promptedit_test`; for an existing volume, create the test database separately if missing using `docker compose exec pgsql psql -U promptedit -d promptedit -c 'CREATE DATABASE promptedit_test OWNER promptedit;'`.

Configure your local environment using `.env.example`: keep the database queue, set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` from a **local** Pixelforge key, and expose port 8000 with ngrok, Expose or Cloudflare Tunnel. Set that key's webhook URL to `https://<tunnel>/webhooks/pixelforge`. Start the worker in another terminal:

```bash
php artisan queue:work --timeout=60
```

For frontend development, run `npm run dev` separately. Images default to `storage/app/private/images` and need no public storage symlink.

To capture real provider responses with the configured local key, run `php artisan app:capture-pixelforge-fixtures`. The command refuses every environment except `local`, creates two low-quality generations, polls every ten seconds, and writes sanitized response bodies, HTTP statuses and selected headers to `tests/Fixtures/pixelforge/real-*.json`. It also captures pricing, blocked/overloaded refusals, usage and a generation list. These direct API captures consume quota and their successful generation contributes to provider usage; account for it separately when reconciling app records. See [the live acceptance evidence](docs/live-acceptance-2026-11-19.md) for the verified run.

For an offline demo, run `php artisan db:seed --class=DemoSeeder` and sign in as `demo@example.com` using `DEMO_PASSWORD` (local default: `password`). This repeatable local-only seeder adds $5, one sample in every status and a bundled image without provider calls or queued jobs; its pending samples intentionally stay pending. It seeds documented pricing only as a missing last-known fallback, leaving live pricing untouched.

### Operator account

Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` (at least 12 characters), optionally `ADMIN_NAME` (defaults to `Admin`), then run:

```bash
php artisan app:create-admin
```

The command creates or updates the account by email, sets admin access and verifies its email; repeated runs do not create duplicates and do update the configured name/password. It reads configuration, so cached config works; after changing variables, rebuild the configuration cache or redeploy Cloud before running it. It never prints the password. Use a lowercase email, consistent with registration/login normalization.

### Tests and CI

```bash
composer ci:check
npm run build
```

`ci:check` runs frontend lint/format checks, Vue TypeScript checks, the PHP test suite, Pint in check mode and PHPStan/Larastan. Tests use **Postgres**, with `promptedit_test` forced by `phpunit.xml`; provider calls use HTTP fakes and pricing fixtures. GitHub Actions provisions Postgres 17, creates that test database, uses PHP 8.5 / Node 22, builds with `npm ci && npm run build`, and runs `composer ci:check`.

## With more time

- An atomic account-wide submission semaphore and real concurrent-worker stress tests.
- Operator recovery for abandoned submissions, stalled holds and exhausted sync/image jobs.
- Alerts on reconciliation mismatches, pricing overruns and failed jobs.
- Real payments, purchase limits and a defined policy for prices exceeding holds.
