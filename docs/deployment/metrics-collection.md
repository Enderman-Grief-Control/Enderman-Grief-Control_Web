# Production Metrics Collection

Production metric snapshots are collected by GitHub Actions, not by the Render
web service. The scheduled workflow runs Artisan directly against the production
Supabase PostgreSQL database.

## Workflow

- Workflow file: `.github/workflows/metrics-collection-scheduled.yml`
- GitHub Actions environment: `production`
- Schedule: `17 */6 * * *`
- Target cadence: about every 6 hours, at approximately 00:17, 06:17, 12:17,
  and 18:17 UTC
- Manual trigger: enabled through `workflow_dispatch`
- Concurrency group: `production-metrics-collection`

GitHub scheduled workflow timing is best effort, so individual runs may start a
little later than the cron minute.

## Required Configuration

Configure these as GitHub Actions environment secrets on the `production`
environment. Do not commit secret values.

```text
APP_KEY
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
CURSEFORGE_API_KEY
```

Configure this as an optional GitHub Actions environment variable on the
`production` environment:

```text
APP_URL
```

The workflow sets production runtime defaults for the command itself, including
`DB_CONNECTION=pgsql`, `DB_SSLMODE=require`, database-backed session/cache/queue
drivers, log mail, and local filesystem storage.

## What Each Run Does

1. Checks out the repository with read-only contents permissions.
2. Sets up PHP and Composer through `.github/actions/setup-laravel-artisan`.
3. Checks that the required database secrets are present by name.
4. Runs `php artisan migrate:status --no-interaction`.
5. Counts existing `metric_snapshots`.
6. Checks that `CURSEFORGE_API_KEY` is present, then runs
   `php artisan metrics:collect`.
7. Counts `metric_snapshots` again and fails if the count did not increase.

## Manual Production Run

To run collection manually:

1. Open GitHub Actions.
2. Select **Scheduled Metrics Collection**.
3. Choose **Run workflow** on the default branch.
4. Wait for the `Collect production metrics` job to finish.

Use manual runs for controlled reruns or production validation after changing
secrets, provider configuration, migrations, or collection code.

## Verifying Success

In GitHub Actions:

- The workflow run should finish green.
- The logs should include `Snapshot count before collection` and
  `Snapshot count after collection`.
- The after count should be greater than the before count.

In the dashboard:

- Sign in to the private dashboard.
- Confirm the latest metric snapshot time reflects the most recent successful
  scheduled or manual collection run.
- Confirm Modrinth and CurseForge distribution totals render as expected.

## Common Failures And First Checks

- Missing secret failure: confirm the named secret exists on the GitHub Actions
  `production` environment, not only at repository level.
- Migration status failure: confirm the Supabase database is reachable from
  GitHub Actions and that production migrations have been applied.
- CurseForge key failure: confirm `CURSEFORGE_API_KEY` exists and is valid.
- Provider request failure: check the provider response in the workflow logs and
  rerun only after confirming the issue is transient or fixed.
- Snapshot count did not increase: inspect `php artisan metrics:collect` output,
  provider configuration, and database write permissions before rerunning.

Render is intentionally not part of scheduled collection. A sleeping Render free
service does not prevent GitHub Actions from writing production snapshots.
