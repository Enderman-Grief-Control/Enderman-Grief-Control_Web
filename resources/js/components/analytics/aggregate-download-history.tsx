import { useState } from 'react';
import {
    DownloadHistoryChart,
    historyDate,
} from '@/components/analytics/download-history-chart';
import type { DownloadHistoryPlot } from '@/components/analytics/download-history-chart';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDownloadHistory } from '@/hooks/use-download-history';
import type { DashboardAnalytics, HistoryRangeKey } from '@/types';

const ranges: { key: HistoryRangeKey; label: string }[] = [
    { key: '7d', label: '7D' },
    { key: '30d', label: '30D' },
    { key: '90d', label: '90D' },
    { key: 'all', label: 'All' },
];

export function AggregateDownloadHistory({
    analytics,
    range,
    rangeError,
    changeRange,
}: {
    analytics: DashboardAnalytics;
    range: HistoryRangeKey;
    rangeError: boolean;
    changeRange: (range: HistoryRangeKey) => void;
}) {
    const [view, setView] = useState<'total' | 'distributions'>('total');
    const anchor = analytics.latestCollection.capturedAt;
    const identitySet = JSON.stringify(
        analytics.distributions.map((distribution) => [
            distribution.id,
            distribution.versions
                .map((version) => version.id)
                .sort((a, b) => a - b),
        ]),
    );
    const { state, retry } = useDownloadHistory(range, anchor, identitySet);
    const data =
        state.status === 'ready' && state.data.scope === 'aggregate'
            ? state.data
            : null;
    const labels = Object.fromEntries(
        analytics.distributions.map((distribution) => [
            distribution.id,
            distribution.name,
        ]),
    );
    const plots: DownloadHistoryPlot[] =
        data === null
            ? []
            : view === 'total'
              ? [
                    {
                        key: data.total.key,
                        label: 'Combined total',
                        points: data.total.points,
                        sampling: data.total.sampling,
                    },
                ]
              : data.series.map((series) => ({ ...series }));
    const partial = data?.total.points.some(
        (point) => point.status === 'partial',
    );

    return (
        <Card className="min-w-0 gap-4">
            <CardHeader className="gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle>Recorded download history</CardTitle>
                    <div
                        role="group"
                        aria-label="History range"
                        className="flex flex-wrap gap-1"
                    >
                        {ranges.map((item) => (
                            <Button
                                key={item.key}
                                type="button"
                                size="sm"
                                variant={
                                    range === item.key ? 'default' : 'outline'
                                }
                                aria-pressed={range === item.key}
                                onClick={() => changeRange(item.key)}
                            >
                                {item.label}
                            </Button>
                        ))}
                    </div>
                </div>
                <CardDescription>
                    Cumulative downloads from stored snapshots, through{' '}
                    {historyDate(anchor)}.{' '}
                    {range === 'all'
                        ? 'All available stored history.'
                        : `Trailing ${range.slice(0, -1)} elapsed days from that collection.`}
                </CardDescription>
                <fieldset className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <legend className="sr-only">History view</legend>
                    {(
                        [
                            { value: 'total', label: 'Combined total' },
                            {
                                value: 'distributions',
                                label: 'By distribution',
                            },
                        ] as const
                    ).map((item) => (
                        <label
                            key={item.value}
                            className="flex cursor-pointer items-center gap-2"
                        >
                            <input
                                type="radio"
                                name="aggregate-history-view"
                                value={item.value}
                                checked={view === item.value}
                                onChange={() => setView(item.value)}
                                className="accent-primary focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2"
                            />
                            {item.label}
                        </label>
                    ))}
                </fieldset>
            </CardHeader>
            <CardContent className="min-w-0 space-y-4">
                {rangeError && (
                    <p role="status" className="text-destructive text-sm">
                        Could not save the range to the URL. Choose a range to
                        try again.
                    </p>
                )}
                {anchor === null ? (
                    <p
                        role="status"
                        className="text-muted-foreground py-10 text-center text-sm"
                    >
                        No history collected yet. Refresh the dashboard after a
                        successful collection.
                    </p>
                ) : state.status === 'loading' ? (
                    <div
                        role="status"
                        aria-live="polite"
                        className="text-muted-foreground flex h-[300px] items-center justify-center rounded-lg border border-dashed text-sm"
                    >
                        Loading{' '}
                        {ranges.find((item) => item.key === range)?.label}{' '}
                        download history…
                    </div>
                ) : state.status === 'error' ? (
                    <div
                        role="status"
                        className="flex min-h-40 flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-6 text-center text-sm"
                    >
                        <p>
                            Download history could not be loaded. Your summaries
                            and detail rows are still available.
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
                            <p
                                role="status"
                                aria-live="polite"
                                className="sr-only"
                            >
                                {
                                    ranges.find((item) => item.key === range)
                                        ?.label
                                }{' '}
                                download history loaded.
                            </p>
                            {view === 'total' && partial && (
                                <p className="text-muted-foreground rounded-lg border p-3 text-sm">
                                    Some points have incomplete coverage. The
                                    combined line appears only when every
                                    listing has a usable count. Known subtotals
                                    and contributor source times are available
                                    under Show plotted values.
                                </p>
                            )}
                            {view === 'total' &&
                                data.total.sampling.originalObservationCount ===
                                    1 && (
                                    <p className="text-muted-foreground text-xs">
                                        One recorded capture time in this range.
                                        Any complete value is shown with a
                                        marker.
                                    </p>
                                )}
                            {view === 'distributions' && (
                                <ul className="text-muted-foreground space-y-1 text-xs">
                                    {data.series
                                        .filter(
                                            (series) =>
                                                series.historyStatus !==
                                                'ready',
                                        )
                                        .map((series) => (
                                            <li key={series.key}>
                                                {series.label}:{' '}
                                                {series.historyStatus ===
                                                'never_collected'
                                                    ? 'Not collected yet'
                                                    : series.historyStatus ===
                                                        'single_point'
                                                      ? 'One capture in this range; shown with a marker'
                                                      : 'No captures in this range; any last-known boundary is labelled in the values table'}
                                            </li>
                                        ))}
                                </ul>
                            )}
                            {data.budget.exceeded && (
                                <p className="text-muted-foreground text-xs">
                                    This history is unusually large and may take
                                    longer to display.
                                </p>
                            )}
                            <DownloadHistoryChart
                                key={`${range}:${anchor}:${view}:${identitySet}`}
                                name={
                                    view === 'total'
                                        ? 'Combined recorded download history'
                                        : 'Recorded downloads by distribution'
                                }
                                domain={data.domain}
                                plots={plots}
                                distributionLabels={labels}
                            />
                        </>
                    )
                )}
            </CardContent>
        </Card>
    );
}
