# Production Metrics Collection

Production metric snapshots are collected by GitHub Actions, not by the Render
web service. The scheduled workflow runs Artisan directly against the production
Supabase PostgreSQL database.

For the full service map, see
[Deployment Architecture](architecture.md).

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

The command writes aggregate distribution snapshots first, then collects
version/file detail snapshots for each successfully collected distribution.
Aggregate rows in `metric_snapshots` remain the canonical dashboard total
history. Detail rows in `distribution_versions` and
`distribution_version_snapshots` are collected for later growth, loader,
version, and file-level analytics.

If aggregate collection fails for a distribution, detailed collection is skipped
for that distribution and the workflow fails. If aggregate collection succeeds
but detailed collection fails, the already-written aggregate row remains in
place and the workflow still fails so the degraded detailed collection is
visible in GitHub Actions.

Provider caveats:

- Modrinth project totals may not exactly match the sum of Modrinth version
  downloads, so aggregate and detailed views should remain clearly labeled.
- Modrinth loader-aware analysis depends on version records continuing to carry
  distinct loader metadata.
- CurseForge detail collection uses file-level records for the existing Fabric
  and Paper/Bukkit distributions and requires a valid `CURSEFORGE_API_KEY`.

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
- The command output should include one aggregate collection line and one
  detail collection line for each active supported distribution.
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
- Detailed metric failure: inspect the `Failed to collect detailed metrics`
  command output. The aggregate snapshot for that distribution may already have
  been written.
- Snapshot count did not increase: inspect `php artisan metrics:collect` output,
  provider configuration, and database write permissions before rerunning.

Render is intentionally not part of scheduled collection. A sleeping Render free
service does not prevent GitHub Actions from writing production snapshots.
