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

## Database

- Local development uses PostgreSQL via `docker compose up -d` (one service,
  port `5432`, named volume `egc-postgres-data`) or any local PostgreSQL
  instance reachable via the `DB_*` variables in `.env`.
- Production is expected to use a managed PostgreSQL provider (e.g. Neon,
  Railway, Render, Supabase-as-Postgres). This repo does not configure or
  assume self-hosted production PostgreSQL.
- Tests run against an in-memory SQLite database (see `phpunit.xml`) and do
  not require PostgreSQL to be running.

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
