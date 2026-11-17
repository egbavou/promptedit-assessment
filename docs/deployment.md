# Deploy to Laravel Cloud

These steps prepare the assessment deployment; no deployment or provider key is created by this repository. Keep credentials in Cloud's environment settings or Secrets Manager.

## 1. Create the application

In Laravel Cloud, choose **New application**, connect GitHub, select this repository and the deployment branch, then choose the region. Follow the [official quickstart](https://laravel.com/cloud/docs/quickstart).

In **General Settings > Runtime**, select **PHP 8.5** ([supported runtimes](https://laravel.com/cloud/docs/runtimes)). Use Flex App compute with scale to zero. Keep the Scheduler toggle disabled: `routes/console.php` defines no scheduled tasks, and generation sync uses delayed jobs. Cloud's [scheduler documentation](https://laravel.com/cloud/docs/scheduled-tasks) explains that scheduled work wakes sleeping environments.

## 2. Attach Postgres

Choose **Add database > Laravel Serverless Postgres**, select **Postgres 17** to match local/CI, create the application database and attach it in the same region as the App cluster. Enable database scale to zero as appropriate. Cloud injects the database connection settings; do not copy local Docker credentials into Cloud. See [Serverless Postgres](https://laravel.com/cloud/docs/resources/databases/postgres).

## 3. Add the managed queue

Choose **Add compute > Managed queue**. Create one **standard** queue using **Flex**, initially 256 MiB, with a minimum of zero and maximum of three workers; make it the default queue. The [managed queue docs](https://laravel.com/cloud/docs/queues) specify Laravel 13.19+ and `aws/aws-sdk-php`, standard delays up to 900 seconds, Flex runtime up to 90 seconds, and Starter's three-worker maximum.

This app includes the required SDK and locks Laravel 13.42. Cloud injects `QUEUE_CONNECTION=cloud`; Laravel's `CloudBootstrapper` supplies the connection and connector automatically. Leave that injected setting intact; there is no separate `queue:work` process or custom worker cluster to configure. All three jobs use 60-second timeouts; their delays are listed in the [README](../README.md#managed-queue-limits).

## 4. Attach private image storage

Choose **Add bucket > Laravel Object Storage** and attach a **private** bucket with disk name `cloud-images`. Set `IMAGES_DISK` to that exact disk name. Cloud configures attached Laravel filesystem disks automatically; the app already includes the required `league/flysystem-aws-s3-v3` adapter. Do not add a hard-coded disk or bucket credentials to `config/filesystems.php`.

Bucket visibility applies to all objects; keep this bucket private because the app streams images only to their owner. Object storage persists across deployments and is shared between web and managed workers, unlike their ephemeral filesystems. See [Object Storage](https://laravel.com/cloud/docs/resources/object-storage).

## 5. Configure environment variables

Review these names in **Environment settings > Environment variables**, supplying credentials privately. App environment must be production, debugging disabled, application URL the assigned HTTPS Cloud URL, cache/session drivers database, and the image disk the attached bucket's disk name. Mail configuration is optional and only needed for password reset; this deployment does not configure a mail transport.

| Purpose                                                                       | Variable names                                                                                                                  |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Application                                                                   | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`                                                                        |
| Persistent cache and sessions                                                 | `CACHE_STORE`, `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`                                                                        |
| Provider                                                                      | `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, `PIXELFORGE_BASE_URL`                                                        |
| Operator command                                                              | `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`                                                                                   |
| Images                                                                        | `IMAGES_DISK`                                                                                                                   |
| Optional SMTP mail for password reset                                         | `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Cloud-managed database settings: inspect, do not override with local settings | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`                                              |
| Cloud-managed queue setting: leave injected                                   | `QUEUE_CONNECTION`                                                                                                              |

Ensure `APP_KEY` is configured and retained across deployments. Use a separate **production** Pixelforge key, with webhook URL `https://<app>.laravel.cloud/webhooks/pixelforge`; put that key's signing secret in `PIXELFORGE_WEBHOOK_SECRET`. Keep the key isolated from local usage so reconciliation covers exactly this deployment.

Admin email should be lowercase; the password must have at least 12 characters. The default admin name is `Admin`. Do not place real values in this guide, `.env.example`, GitHub Actions or source control.

Cloud injects attached resource credentials; custom variables override those injected settings. Redeploy after changing variables so cached configuration and workers receive them. See [environment variables](https://laravel.com/cloud/docs/environments#environment-variables).

## 6. Set build and deploy commands

Include production PHP dependency installation in the build steps, followed by the requested frontend build command:

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci && npm run build
```

If using configuration caching, add `php artisan optimize` to the **build** steps after these commands. Set the **deploy command** to:

```bash
php artisan migrate --force
```

Build/deploy commands each have a 15-minute limit; deploy filesystem changes are discarded. Cloud restarts workers on deployment, so no queue restart, storage symlink or cache-clearing deploy command is needed. See [build and deploy commands](https://laravel.com/cloud/docs/environments#build-and-deploy-commands) and the [Laravel deploy guide](https://laravel.com/cloud/docs/deploy-guides/laravel).

## 7. Deploy and create the operator

Deploy the environment and check the build/migration logs. In the environment's **Commands** tab, start a console session (Cloud's dashboard command runner), then run:

```bash
php artisan app:create-admin
```

The command uses the deployed configuration and prints only the account email. Running it again updates the same account. See [Cloud Commands](https://laravel.com/cloud/docs/environments#commands); one-off API/CLI commands are a separate option.

Set `APP_URL` to the final `https://<app>.laravel.cloud` address and redeploy if it changed after initial provisioning. Laravel 13's built-in `TrustProxies` recognizes the Cloud environment and trusts forwarded protocol headers; HTTPS image-route and signed-URL behavior is covered by `CloudHttpsTest`. Console-generated URLs use `APP_URL`. No blanket proxy override or forced scheme is necessary; see Laravel's [trusted proxy guidance](https://laravel.com/framework/docs/requests#configuring-trusted-proxies) and Cloud's [domains](https://laravel.com/cloud/docs/domains).

## Post-deploy checklist

- Replace the README live URL placeholder with the assigned HTTPS URL.
- Send a signed test event from the production key's Pixelforge dashboard to `/webhooks/pixelforge`; confirm **204** in the delivery log. A bad signature returns 400; a missing signing secret returns 500.
- Register a reviewer account and land on **Generate**.
- Click **Buy $5**, submit one `[succeed]` generation, and confirm it becomes succeeded, the hold is released and exactly one charge appears.
- Open its image and price breakdown. Confirm the image route uses HTTPS, the object exists in the private bucket, and another user cannot read it.
- Sign in as the admin and open `/admin/report`; confirm the completion day's exact cost/count matches Pixelforge. Usage responses can remain cached for five minutes.
- Check managed queue failures/logs, then exercise `[fail]`, `[slow]`, `[duplicate]`, `[overloaded]` and `[blocked]` as part of the separate live acceptance pass. Confirm the Scheduler remains disabled and leave a few real provider generations for reviewers.
