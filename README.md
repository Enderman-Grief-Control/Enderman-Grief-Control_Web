# Enderman Grief Control — Web

Laravel + React (TypeScript) + Inertia + Tailwind CSS + shadcn/ui, backed by
PostgreSQL.

## Stack

- **Backend:** Laravel 13, PostgreSQL
- **Frontend:** React 19 + TypeScript, Inertia, Tailwind CSS v4, shadcn/ui
- **Auth:** Laravel Fortify (session-based). Public registration is disabled
  — see [Auth notes](#auth-notes) below.

## Requirements

- PHP 8.4+ with the `pdo_pgsql` extension (e.g. [Laravel Herd](https://herd.laravel.com/))
- Composer
- Node.js 22+ and npm
- Docker Desktop (for local PostgreSQL via Docker Compose), or a local
  PostgreSQL install

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Start local PostgreSQL (or point DB_* in .env at your own instance)
docker compose up -d

php artisan migrate
npm run build   # or `npm run dev` for a live-reloading dev build
composer run dev   # runs the PHP server, queue listener, and Vite dev server together
```

The app serves at `http://localhost:8000` by default.

## Public site scaffold

The public Astro site lives in `site/` and uses its own npm dependencies and
lockfile. Root npm and Composer commands remain scoped to the private
Laravel/Inertia dashboard.

```bash
cd site
npm install
npm run dev
```

The Astro dev server prints its local URL when it starts.

To build and preview the public site:

```bash
cd site
npm run build
npm run preview
```

Netlify builds the public Astro site from `site/`. Render builds the private
Laravel app from the repository root.

## Database

- Local development uses PostgreSQL via `docker compose up -d` (one service,
  port `5432`, named volume `egc-postgres-data`) or any local PostgreSQL
  instance reachable via the `DB_*` variables in `.env`.
- Production is expected to use a managed PostgreSQL provider (e.g. Neon,
  Railway, Render, Supabase-as-Postgres). This repo does not configure or
  assume self-hosted production PostgreSQL.
- Tests run against an in-memory SQLite database (see `phpunit.xml`) and do
  not require PostgreSQL to be running.

## Deployment

Render deployment notes live in
[`docs/deployment/render.md`](docs/deployment/render.md). The current
production-shaped direction is a Docker-backed Render Web Service connected to
managed PostgreSQL.

Production metrics collection notes live in
[`docs/deployment/metrics-collection.md`](docs/deployment/metrics-collection.md).
The scheduled collector runs through GitHub Actions against Supabase
PostgreSQL; Render does not run the production collection schedule.

## Metrics collection

The authenticated dashboard reads stored metric snapshots. It does not call
Modrinth or CurseForge during page render.

Run a local one-off collection after migrations and seed data are in place:

```bash
php artisan metrics:collect
```

Required server-side configuration:

- `CURSEFORGE_API_KEY` must be set for active CurseForge distributions.
- Modrinth collection currently uses public project metadata and does not need
  an API key.

The Laravel scheduler registers `metrics:collect` every 6 hours for hosts that
run `php artisan schedule:run`. Current production collection is instead driven
by GitHub Actions so it can run independently of the Render web service.
Scheduled runs use the same behavior as manual runs. Missing CurseForge
credentials or provider errors cause the command to fail; details are emitted
to the command output and Laravel logs. Successful reruns append new snapshots
instead of deduplicating captures.

To verify local collection, inspect the latest snapshots with Tinker:

```bash
php artisan tinker
```

```php
App\Models\MetricSnapshot::query()
    ->with('distribution:id,provider,name')
    ->latest('captured_at')
    ->take(5)
    ->get(['id', 'distribution_id', 'downloads', 'captured_at']);
```

## Auth notes

This app uses Laravel Fortify for session-based auth. Public registration is
intentionally disabled (`config/fortify.php`) since this project is a private
analytics dashboard, not a public multi-tenant app — accounts are provisioned
manually (e.g. via `php artisan tinker` or a seeder). Password reset, email
verification, two-factor authentication, and passkeys remain available.

Follow-up: revisit whether any of the remaining Fortify features (2FA,
passkeys, email verification) should be trimmed further as part of a later
dashboard-security PRD.

## Quality checks

```bash
composer run test     # Pint, PHPStan, and the PHPUnit suite
npm run check          # frontend lint/format check
npm run types:check    # TypeScript check
```

> **Windows note:** PHPStan's parallel worker can fail on some Windows PHP
> builds with a "turbo-ext" DLL load error. If that happens, run it directly
> with a higher memory limit instead: `vendor/bin/phpstan analyse --memory-limit=1G`.
