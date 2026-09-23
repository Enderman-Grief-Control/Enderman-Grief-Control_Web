# Render Deployment

This project deploys to Render as a Docker-backed Web Service. Render does not
currently provide a native PHP runtime for this Laravel app, so the repository
Dockerfile is the source of truth for the first Render validation.

## Service

- Service type: Web Service
- Runtime: Docker
- Plan: Free initially; remain on Free while project requirements fit the tier
- Root directory: repository root
- Persistent disk: none
- Scheduler: none on Render

The container serves Laravel from `public/` through Apache and listens on
Render's `PORT` environment variable. Apache enables `mod_rewrite` and allows
Laravel's `public/.htaccess` overrides for application routing.

## Build Shape

The Docker image installs:

- PHP 8.4 with `pdo_pgsql` and `pgsql`
- Composer 2
- Node.js 22 and npm for the Vite build

The image build runs:

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build
```

`npm run build` invokes Wayfinder through Artisan, so PHP and Composer
dependencies must be available before the frontend build runs. The Dockerfile
sets temporary Laravel defaults only on build commands that may boot Artisan.
Those fake values are not persisted as image-level runtime defaults. Render
runtime environment variables must provide the real production values.

## Runtime Environment

Set these in Render. Do not commit secret values.

```text
APP_NAME
APP_ENV=production
APP_KEY
APP_DEBUG=false
APP_URL
APP_LOCALE
APP_FALLBACK_LOCALE
APP_FAKER_LOCALE
BCRYPT_ROUNDS
LOG_CHANNEL
LOG_STACK
LOG_LEVEL

DB_CONNECTION=pgsql
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
DB_SSLMODE=require

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

VITE_APP_NAME
```

Provider variables should not be set on Render under the current architecture.
Scheduled metric collection is intended to run independently through GitHub
Actions, which will receive the provider credentials required by
`metrics:collect` in a later workflow.

```text
CURSEFORGE_API_KEY
MODRINTH_USER_AGENT
```

## First Validation

1. Create a Render Web Service from the repository.
2. Select Docker as the runtime.
3. Use the repository root.
4. Configure the runtime environment variables above.
5. Deploy and inspect logs for PHP extension, Composer, npm, Wayfinder, and
   Vite failures.
6. Confirm the app responds at the Render URL.
7. Confirm login and dashboard behavior against the already-migrated Supabase
   database.

Do not run migrations from the web start command. If migrations are needed
during validation, run them deliberately from a controlled local command or a
later validated automation path.
