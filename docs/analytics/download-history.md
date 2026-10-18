# Download History

The private dashboard plots cumulative recorded downloads from stored snapshots.
Aggregate distribution counts come from `metric_snapshots`; version/file counts
come from `distribution_version_snapshots`. Project totals use aggregate counts,
never a sum of version/file counts. Viewing history does not call providers or
write application data.

## Dashboard Controls

Recorded download history appears after Recent growth. It starts on Combined
total; By distribution shows the active distribution series instead. The existing
summary cards, growth figures, version/file rows, and remove/restore actions
remain available.

| Chart | Available ranges | Initial range | Persistence |
| --- | --- | --- | --- |
| Aggregate | 7D, 30D, 90D, All | 30D | `historyRange` in the dashboard URL restores the range on reload or bookmark |
| Version/file, inside each expanded distribution | 7D, 30D | 7D | Each panel keeps its own choice across collapse/reopen and page-state-preserving refreshes; a full reload resets it |

Every available version/file starts selected, including Missing rows with older
history and identities that have never been collected. Individually labeled
checkboxes, Select all, and Clear control visibility without changing counts,
coverage, availability, or requests. There is no top-N cap. Duplicate display
names include the local ID; series colors and dash patterns derive from identity.

Choices for surviving identities persist across range changes, collapse/reopen,
and page-state-preserving refreshes. Removed identities are forgotten;
new/restored identities start selected. A full reload selects all again.
Removing a Missing version/file omits it from the chart without deleting its
snapshots. Availability is evaluated now, rather than reconstructed historically.

Show plotted values opens a semantic table with 50 rows per page, source capture
times, and observation/coverage states. Combined rows also expose each
contributor and a separately labeled known subtotal when coverage is partial.
The table uses the returned plotted data, including any reduction; it is not a
complete snapshot export.

## Time And Recorded Values

All charts use the dashboard's newest aggregate capture across active
distributions as their anchor. They do not use wall-clock time or a separate
newest capture per series. Detail observations after that anchor wait until a
later dashboard refresh includes them, even when they were captured only seconds
after the aggregate snapshot.

Finite ranges include both endpoints and cover trailing elapsed 24-hour days in
UTC. All starts at the earliest stored aggregate or currently available detail
capture across active distributions, at or before the anchor. All describes
stored history, not the project's lifetime. Timestamps are serialized in UTC and
displayed in the browser's local timezone; display formatting does not change
range membership. Duplicate captures at one timestamp use the largest snapshot
ID for that identity.

Lines use steps after each observation, without smoothing, animation, or joining
across gaps. Plateaus, jumps, actual zeroes, and downward corrections are retained.
A version/file begins at its first capture, not its publication date. A single
capture has a marker; an empty series stays labeled and selectable. The existing
24h/7d/30d growth summaries have separate baseline rules and keep their meaning.

## Freshness, Gaps, And Combined Totals

A recorded count can be carried forward only while its source is strictly less
than 12 elapsed hours old. A usable pre-range observation can provide a labeled
last-known value at the range start. Carrying preserves the original source time;
it does not create a stored capture or infer downloads between collections.

At exactly 12 hours without another observation, the value becomes unknown and
the line breaks. A carried endpoint one millisecond before expiry and a null
point at expiry place the break accurately. A real capture exactly at expiry
renews freshness without a gap. Repeated unchanged captures also renew freshness.
This history policy is separate from the latest-summary 30-minute collection
window.

Combined history aligns the full aggregate streams at their observation and
freshness-boundary times. A complete-total value requires a usable count from
every currently active distribution. With some contributors usable, the total
is null and a known subtotal is labeled separately; with none usable, the total
is unavailable. Unknown counts never become zero or a complete total for a
changing subset. Contributor states distinguish observed, carried, stale, and
unknown sources. No later observation supplies a value at an earlier time.

For example, if one distribution was last captured at 00:00 and another at
06:00, the first may contribute its carried count at 06:00. At 12:00 it expires
unless refreshed, so the combined line breaks even if the second remains fresh.

Version/file downloads may differ from the distribution total. CurseForge file
counts can lag or refresh in batches; the charts preserve the recorded plateaus
and jumps. Their timing does not prove when real downloads occurred. The
CurseForge discrepancy investigation remains deferred; no backfill, counter
repair, or collection change is part of visualization.

## Loading And Read Boundary

`ReadDownloadHistory` coordinates scoped reads (`HistoryObservations`) and
alignment/reduction (`HistoryTimeline`). It returns the typed aggregate or
version/file response; React formats points and manages visibility.

`GET /dashboard/history`, named `dashboard.history`, uses the dashboard's
existing `auth` and `verified` middleware. Its query parameters are:

| Parameter | Contract |
| --- | --- |
| `range` | `7d`, `30d`, `90d`, or `all`; omission defaults to `30d` |
| `distribution` | Optional positive active-distribution ID; omission requests aggregate history |
| `anchor` | Optional UTC ISO timestamp ending in `Z`, with up to six fractional digits; must match a stored active aggregate capture; omission resolves the current anchor |

Malformed input returns JSON 422; a well-formed missing/inactive distribution ID
returns 404. The endpoint accepts no arbitrary file-ID scope and includes only
currently available versions/files. An older stored aggregate anchor remains
usable after a later collection, subject to current availability. The dashboard
page's separate `historyRange` parameter defaults or normalizes unsupported
strings to `30d` and rejects non-scalar input with 422.

The initial page includes summaries and version/file rows. Aggregate history
loads after mount; detailed history loads only when a distribution opens. A
dashboard queue permits at most two active history requests. Aggregate range
changes use a range-only Inertia visit that updates the URL without re-reading
summaries. Detail range changes are independent and keep that URL unchanged.

Requests pin to the displayed aggregate anchor. Successful results are cached in
component memory by scope, range, anchor, and available identities; detail keys
also include the distribution and a remove/restore revision. Collapse/reopen
reuses results. Anchor/availability changes invalidate caches, successful
remove/restore invalidates the affected detail cache, and page unmount discards
them. Superseded requests are aborted and generation-checked before applying
successes or failures. A refresh that supplies a new anchor refreshes history.

Loading replaces the previous plot for a changed range. Failure offers Retry;
expired authentication redirects to login. A null aggregate anchor shows an
empty state without starting history requests. Summary/table actions remain
available during chart loading or failure.

## Reduction And Performance

Responses target at most 20,000 plotted points and 3 MiB of compact JSON per
request, with ordinary exact series limited to 1,500 points. Above the series or
scope point threshold, equal elapsed-time buckets retain first, minimum,
maximum, and last observations. The bucket count is capped at 120 and scales
down with the number of series. Detailed reads reduce in SQL after calculating
full-history neighbors; aggregate totals align the full stream before reduction.
Checkbox visibility does not change sampling.

First/last and boundary points, both sides of downward corrections, real
freshness gaps, and total completeness transitions take priority over size
targets. Protected exceptions can exceed the targets; response metadata and the
UI disclose that condition instead of silently truncating history or identities.
Omitted captures do not create false freshness gaps. Reduction can omit
intermediate positive steps and short plateaus; it never invents averages or
earlier captures. A reduced-overview note reports original/returned observation
counts and bucket duration.

SQLite tests and live PostgreSQL reads exercise the query path. The recorded
2026-10-18 verification measured 3/5 aggregate and 4/6 detail analytics queries
for finite/All ranges. All 48 generated growth responses through five years
stayed within the response targets; the largest five-year All SQL read took
about 7.3 seconds. The 16 current-data PostgreSQL responses stayed below 0.6
seconds of reader/JSON time. These are measured fixtures and read-only database
results, not browser timings or a production latency guarantee. Large growth
fixtures were measured on SQLite, not PostgreSQL. Budgets apply per response;
several expanded panels can together transfer more than 3 MiB.

Charts require the existing `distribution_versions.excluded_at` migration
(`2026_10_08_000001_add_excluded_at_to_distribution_versions_table`). Visualization
adds no new migration or index. Follow the existing
[controlled migration process](../deployment/render.md#first-validation) when
preparing an environment; a chart read never applies migrations.

## Verification Limits And Follow-Up

The 2026-10-18 verification recorded 166 backend tests passed, 2 skipped, PHPStan
passed, tracked-PHP Pint passed, frontend type/check commands passed, and client
and SSR builds passed. Root Pint reported formatting failures in ignored local
scripts. Existing optional-fontaine and Inertia sourcemap build notices remain.
Static-rendering, selection-state, and request-queue checks passed, but do not
establish browser events or fetch-effect behavior.

Maintainer screenshots provide desktop/dark smoke evidence for aggregate 30D
Combined total and 7D By distribution with plotted values. Agent browser access
was unavailable. Integrated browser acceptance remains unverified, including:

- 90D/All and URL reload/bookmark persistence; narrow screens and light theme.
- Keyboard controls, historical table paging, empty/single-point states,
  Retry, login expiry, and delayed or superseded responses.
- Detail toggles and selection across independent ranges, collapse/reopen,
  and Inertia refresh; long/duplicate labels and multiple expanded panels.
- Actual lazy fetch concurrency/cancellation and remove/restore cache
  invalidation, including the earlier manual remove/restore regression.

Presentation feedback remains for later polish: crowded date ticks, weak thin
patterned lines, dense tooltip/explanatory text, and smaller distribution series
compressed by a shared count axis. No redesign is included in this closeout.
M1.4 dashboard security remains the next planned milestone; the inherited
`verified` middleware currently does not enforce email verification because
`User` does not implement `MustVerifyEmail`.
