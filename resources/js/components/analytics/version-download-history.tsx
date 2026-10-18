import {
    DownloadHistoryChart,
    historyDate,
    historyStyle,
} from '@/components/analytics/download-history-chart';
import { Button } from '@/components/ui/button';
import type { useDownloadHistory } from '@/hooks/use-download-history';
import type { useVersionSelection } from '@/hooks/use-version-selection';
import type { DashboardDistribution } from '@/types';

export function VersionDownloadHistory({
    distribution,
    range,
    changeRange,
    anchor,
    history,
    selection,
}: {
    distribution: DashboardDistribution;
    range: '7d' | '30d';
    changeRange: (range: '7d' | '30d') => void;
    anchor: string | null;
    history: ReturnType<typeof useDownloadHistory>;
    selection: ReturnType<typeof useVersionSelection>;
}) {
    const { state, retry } = history;
    const data =
        state.status === 'ready' && state.data.scope === 'versions'
            ? state.data
            : null;
    const nameCounts = new Map<string, number>();
    for (const version of distribution.versions) {
        nameCounts.set(
            version.displayName,
            (nameCounts.get(version.displayName) ?? 0) + 1,
        );
    }
    const labels = new Map(
        distribution.versions.map((version) => [
            version.id,
            `${version.displayName}${(nameCounts.get(version.displayName) ?? 0) > 1 ? ` #${version.id}` : ''}`,
        ]),
    );
    const plots =
        data?.series
            .filter((series) => selection.selected.has(series.id))
            .map((series) => ({
                ...series,
                label: labels.get(series.id) ?? series.label,
            })) ?? [];

    return (
        <section
            className="min-w-0 space-y-4 border-b p-4 sm:p-6"
            aria-label={`${distribution.name} version/file history`}
        >
            <div className="space-y-1">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h4 className="text-sm font-semibold">
                        Version/file recorded downloads
                    </h4>
                    <div
                        role="group"
                        aria-label={`${distribution.name} version/file history range`}
                        className="flex flex-wrap gap-1"
                    >
                        {(['7d', '30d'] as const).map((period) => (
                            <Button
                                key={period}
                                type="button"
                                size="sm"
                                variant={
                                    range === period ? 'default' : 'outline'
                                }
                                aria-pressed={range === period}
                                onClick={() => changeRange(period)}
                            >
                                {period.toUpperCase()}
                            </Button>
                        ))}
                    </div>
                </div>
                <p className="text-muted-foreground text-xs">
                    Range: {range.toUpperCase()}. Through {historyDate(anchor)}.
                </p>
            </div>
            {distribution.versions.length > 0 && (
                <fieldset className="min-w-0 space-y-3">
                    <legend className="text-sm font-medium">
                        Visible versions/files
                    </legend>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={selection.selectAll}
                            disabled={
                                selection.selected.size ===
                                distribution.versions.length
                            }
                        >
                            Select all
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={selection.clear}
                            disabled={selection.selected.size === 0}
                        >
                            Clear
                        </Button>
                        <span
                            role="status"
                            className="text-muted-foreground text-xs"
                        >
                            {selection.selected.size} of{' '}
                            {distribution.versions.length} selected
                        </span>
                    </div>
                    <div className="grid min-w-0 gap-2 md:grid-cols-2">
                        {distribution.versions.map((version) => {
                            const key = `version:${version.id}`;
                            const series = data?.series.find(
                                (item) => item.id === version.id,
                            );
                            return (
                                <label
                                    key={version.id}
                                    className="flex min-w-0 cursor-pointer items-start gap-2 rounded-md border p-3 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        checked={selection.selected.has(
                                            version.id,
                                        )}
                                        onChange={() =>
                                            selection.toggle(version.id)
                                        }
                                        className="accent-primary focus-visible:ring-ring mt-0.5 size-4 shrink-0 focus-visible:ring-2 focus-visible:ring-offset-2"
                                    />
                                    <svg
                                        aria-hidden="true"
                                        width="24"
                                        height="8"
                                        className="mt-1.5 shrink-0"
                                    >
                                        <line
                                            x1="0"
                                            x2="24"
                                            y1="4"
                                            y2="4"
                                            stroke={historyStyle(key).color}
                                            strokeDasharray={
                                                historyStyle(key).dash
                                            }
                                            strokeWidth="2"
                                        />
                                    </svg>
                                    <span className="min-w-0 space-y-1 wrap-anywhere">
                                        <span className="block font-medium">
                                            {labels.get(version.id)}
                                        </span>
                                        <span className="text-muted-foreground block text-xs">
                                            {[
                                                version.versionNumber,
                                                ...version.loaders,
                                            ]
                                                .filter(Boolean)
                                                .join(' / ')}
                                            {version.status === 'missing' &&
                                                ' · Missing from latest collection'}
                                        </span>
                                        {series &&
                                            series.historyStatus !==
                                                'ready' && (
                                                <span className="text-muted-foreground block text-xs">
                                                    {series.historyStatus ===
                                                    'never_collected'
                                                        ? 'Not collected yet'
                                                        : series.historyStatus ===
                                                            'single_point'
                                                          ? 'One capture in this range; shown with a marker'
                                                          : 'No captures in this range; any last-known boundary is labelled in the values table'}
                                                </span>
                                            )}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </fieldset>
            )}
            {distribution.versions.length === 0 ? (
                <p role="status" className="text-muted-foreground text-sm">
                    No available versions/files to chart.
                </p>
            ) : selection.selected.size === 0 ? (
                <p
                    role="status"
                    className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm"
                >
                    Select a version/file to show its history
                </p>
            ) : anchor === null ? (
                <p role="status" className="text-muted-foreground text-sm">
                    No history collected yet. Refresh the dashboard after a
                    successful collection.
                </p>
            ) : state.status === 'loading' ? (
                <div
                    role="status"
                    aria-live="polite"
                    className="text-muted-foreground flex h-[300px] items-center justify-center rounded-lg border border-dashed p-4 text-center text-sm"
                >
                    Loading {range.toUpperCase()} version/file history…
                </div>
            ) : state.status === 'error' ? (
                <div
                    role="status"
                    className="space-y-3 rounded-lg border border-dashed p-6 text-sm"
                >
                    <p>
                        Version/file history could not be loaded. The detail
                        rows remain available.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={retry}
                    >
                        Retry
                    </Button>
                </div>
            ) : (
                data && (
                    <>
                        <p role="status" aria-live="polite" className="sr-only">
                            {range.toUpperCase()} version/file history loaded.
                        </p>
                        {data.budget.exceeded && (
                            <p className="text-muted-foreground text-xs">
                                This history is unusually large and may take
                                longer to display.
                            </p>
                        )}
                        <DownloadHistoryChart
                            key={`${state.key}:${plots.map((plot) => plot.key).join(',')}`}
                            name={`${distribution.name} recorded downloads by version/file`}
                            domain={data.domain}
                            plots={plots}
                        />
                    </>
                )
            )}
        </section>
    );
}
