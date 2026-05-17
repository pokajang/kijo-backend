# KijoV2 Backend

Laravel API backend for KijoV2. Production target for the MVP release is `https://kijo.amiosh.com`.

## Production Release Checklist

1. Point the hosting web root to Laravel `public/`, not the project root.
2. Back up the production database and `storage/app` before changing files or running migrations.
3. Deploy code and install dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

4. Create production `.env` values from `.env.production.example`. Required release posture:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kijo.amiosh.com
PUBLIC_STORAGE_URL=https://kijo.amiosh.com/storage
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
```

5. Run the database migration gate:

```bash
php artisan migrate --force
php artisan migrate:status
```

`migrate:status` must show no pending migrations before the release is considered complete.

6. Prepare storage and move sensitive files private:

```bash
php artisan storage:link
php artisan app:migrate-sensitive-files
php artisan app:migrate-sensitive-files --commit
```

Sensitive files are served through authenticated `/files/private/{token}` URLs. Public media remains limited to `catalog/**`, `whats-new/**`, and `sport-time/**`. During rollout, private file resolution checks `storage/app/private` first and falls back to `storage/app/public` until the migration command has completed.

7. Cache the optimized production bootstrap:

```bash
php artisan optimize
```

8. Configure the Laravel scheduler in shared-hosting cron:

```cron
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

9. Configure queue processing. Prefer a provider-supported long-running worker. If only cron is available, run a bounded worker regularly:

```cron
* * * * * php /path/to/artisan queue:work --stop-when-empty --tries=3 >> /dev/null 2>&1
```

## Release Verification

Run these before and after deployment:

```bash
composer validate --strict
composer audit
php artisan test
php artisan about
```

Production `php artisan about` should report production environment, debug disabled, and cached config/routes after `php artisan optimize`. After `app:migrate-sensitive-files --commit`, direct `/storage/...` access should fail for moved sensitive files, while authenticated private download URLs should work.

## MVP Authorization Note

Invoice and JD14 mutation routes are intentionally open to all authenticated users for this MVP because the system is operated as an open internal workflow. These routes should remain authenticated-only, but not role-gated, unless the product policy changes.
