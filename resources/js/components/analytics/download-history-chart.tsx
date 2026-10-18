import { useId, useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Button } from '@/components/ui/button';
import type {
    HistoryMeta,
    HistoryPoint,
    HistorySampling,
    HistoryTotalPoint,
} from '@/types';

export interface DownloadHistoryPlot {
    key: string;
    label: string;
    points: (HistoryPoint | HistoryTotalPoint)[];
    sampling: HistorySampling;
}

type PlotPoint = (HistoryPoint | HistoryTotalPoint) & {
    timestamp: number;
    marker: boolean;
};

const integer = new Intl.NumberFormat();
const compact = new Intl.NumberFormat(undefined, {
    notation: 'compact',
    maximumFractionDigits: 1,
});
const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
});
const tickDate = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
});

export function historyDate(value: string | null): string {
    return value === null ? 'Unknown' : dateTime.format(new Date(value));
}

// Identity, rather than array position, determines both color and dash pattern.
export function historyStyle(key: string) {
    const hash = Array.from(key).reduce(
        (value, character) => (value * 31 + character.charCodeAt(0)) >>> 0,
        0,
    );

    return {
        color: `var(--chart-${(hash % 5) + 1})`,
        dash: ['', '7 3', '2 3', '9 3 2 3'][Math.floor(hash / 5) % 4],
    };
}

function PointDetails({
    point,
    distributionLabels,
}: {
    point: HistoryPoint | HistoryTotalPoint;
    distributionLabels: Record<number, string>;
}) {
    if ('contributors' in point) {
        const usable = point.contributors.filter(
            (item) => item.downloads !== null,
        ).length;

        return (
            <div className="space-y-1">
                <p className="capitalize">
                    {point.status}: {usable} of {point.contributors.length}{' '}
                    listings
                </p>
                {point.status === 'partial' && (
                    <p>
                        Known subtotal:{' '}
                        {point.knownDownloads === null
                            ? 'Unknown'
                            : integer.format(point.knownDownloads)}
                    </p>
                )}
                <ul className="space-y-1">
                    {point.contributors.map((contributor) => (
                        <li key={contributor.distributionId}>
                            {distributionLabels[contributor.distributionId] ??
                                `Listing #${contributor.distributionId}`}
                            :{' '}
                            {contributor.downloads === null
                                ? 'Unknown'
                                : integer.format(contributor.downloads)}{' '}
                            ({contributor.status}); source:{' '}
                            {historyDate(contributor.sourceCapturedAt)}
                        </li>
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <span>
            {point.kind === 'observed'
                ? 'Observed'
                : point.kind === 'carried'
                  ? 'Last known (carried)'
                  : point.gapReason === 'expired'
                    ? 'Gap: source expired'
                    : 'Gap: not yet observed'}{' '}
            {point.kind !== 'observed' &&
                `· Source: ${historyDate(point.sourceCapturedAt)}`}
        </span>
    );
}

function HistoryTooltip({
    active,
    payload,
    label,
    distributionLabels,
}: {
    active?: boolean;
    payload?: readonly {
        payload?: PlotPoint;
        name?: string | number;
        color?: string;
    }[];
    label?: unknown;
    distributionLabels: Record<number, string>;
}) {
    // Recharts falls back to array position for unmatched asynchronous series.
    // Only show actual points at the active time; never imply that fallback is aligned.
    const entries = payload?.filter(
        (entry) => entry.payload?.timestamp === Number(label),
    );

    if (!active || !entries?.length) {
        return null;
    }

    return (
        <div className="bg-popover text-popover-foreground max-h-72 max-w-72 space-y-3 overflow-auto rounded-lg border p-3 text-xs shadow-md">
            {entries.map(
                (entry) =>
                    entry.payload && (
                        <div key={entry.name} className="space-y-1">
                            <p className="font-semibold">
                                {entry.name}:{' '}
                                {entry.payload.downloads === null
                                    ? 'Unknown'
                                    : integer.format(entry.payload.downloads)}
                            </p>
                            <p>{historyDate(entry.payload.at)}</p>
                            <PointDetails
                                point={entry.payload}
                                distributionLabels={distributionLabels}
                            />
                            {'sourceCapturedAt' in entry.payload &&
                                entry.payload.kind === 'observed' && (
                                    <p>
                                        Source:{' '}
                                        {historyDate(
                                            entry.payload.sourceCapturedAt,
                                        )}
                                    </p>
                                )}
                        </div>
                    ),
            )}
        </div>
    );
}

function PlottedValues({
    plots,
    distributionLabels,
}: {
    plots: DownloadHistoryPlot[];
    distributionLabels: Record<number, string>;
}) {
    const [page, setPage] = useState(0);
    const rows = plots
        .flatMap((plot) =>
            plot.points.map((point) => ({
                key: `${plot.key}:${point.at}`,
                label: plot.label,
                point,
            })),
        )
        .sort((a, b) => Date.parse(a.point.at) - Date.parse(b.point.at));
    const pages = Math.max(1, Math.ceil(rows.length / 50));
    const currentPage = Math.min(page, pages - 1);

    return (
        <details className="rounded-lg border p-3">
            <summary className="focus-visible:ring-ring cursor-pointer rounded-sm text-sm font-medium focus-visible:ring-2 focus-visible:outline-none">
                Show plotted values
            </summary>
            <div
                className="mt-3 min-w-0 overflow-x-auto"
                role="region"
                aria-label="Plotted history values"
                tabIndex={0}
            >
                <table className="w-full min-w-2xl text-left text-xs">
                    <caption className="text-muted-foreground pb-3 text-left">
                        Returned chart points, including carried values and
                        gaps. Times use your browser’s local timezone.
                    </caption>
                    <thead>
                        <tr className="border-b">
                            {[
                                'Series',
                                'Point time',
                                'Downloads',
                                'Source capture',
                                'Point / coverage state',
                            ].map((label) => (
                                <th
                                    key={label}
                                    scope="col"
                                    className="px-2 py-2 font-medium"
                                >
                                    {label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows
                            .slice(currentPage * 50, currentPage * 50 + 50)
                            .map(({ key, label, point }) => (
                                <tr
                                    key={key}
                                    className="border-b align-top last:border-0"
                                >
                                    <th
                                        scope="row"
                                        className="max-w-48 px-2 py-3 font-medium wrap-break-word"
                                    >
                                        {label}
                                    </th>
                                    <td className="px-2 py-3">
                                        {historyDate(point.at)}
                                    </td>
                                    <td className="px-2 py-3 tabular-nums">
                                        {point.downloads === null
                                            ? 'Unknown'
                                            : integer.format(point.downloads)}
                                    </td>
                                    <td className="px-2 py-3">
                                        {'contributors' in point
                                            ? 'See contributor sources'
                                            : historyDate(
                                                  point.sourceCapturedAt,
                                              )}
                                    </td>
                                    <td className="px-2 py-3">
                                        <PointDetails
                                            point={point}
                                            distributionLabels={
                                                distributionLabels
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={currentPage === 0}
                    onClick={() => setPage(currentPage - 1)}
                >
                    Previous
                </Button>
                <p className="text-muted-foreground text-xs" role="status">
                    Page {currentPage + 1} of {pages} ·{' '}
                    {integer.format(rows.length)} points
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={currentPage + 1 >= pages}
                    onClick={() => setPage(currentPage + 1)}
                >
                    Next
                </Button>
            </div>
        </details>
    );
}

export function DownloadHistoryChart({
    name,
    domain,
    plots,
    distributionLabels = {},
}: {
    name: string;
    domain: HistoryMeta['domain'];
    plots: DownloadHistoryPlot[];
    distributionLabels?: Record<number, string>;
}) {
    const id = useId();
    const prepared = plots.map((plot) => ({
        ...plot,
        points: plot.points.map((point, index): PlotPoint => ({
            ...point,
            timestamp: Date.parse(point.at),
            marker:
                point.downloads !== null &&
                ((plot.points[index - 1]?.downloads == null &&
                    plot.points[index + 1]?.downloads == null) ||
                    (plot.sampling.originalObservationCount === 1 &&
                        ('kind' in point
                            ? point.kind === 'observed'
                            : point.contributors.some(
                                  (item) => item.status === 'observed',
                              )))),
        })),
    }));
    const hasValues = plots.some((plot) =>
        plot.points.some((point) => point.downloads !== null),
    );
    const start = domain.startedAt === null ? 0 : Date.parse(domain.startedAt);
    const end = domain.endedAt === null ? 0 : Date.parse(domain.endedAt);
    const timeDomain: [number, number] =
        start === end ? [start - 3600000, end + 3600000] : [start, end];

    return (
        <div
            className="min-w-0 space-y-4"
            role="group"
            aria-label={name}
            aria-describedby={`${id}-description`}
        >
            <p
                id={`${id}-description`}
                className="text-muted-foreground text-xs"
            >
                Last recorded counts between captures. Carried values expire
                after 12 hours; gaps are unknown, not zero. All times are local.
            </p>
            {hasValues ? (
                <div className="h-[300px] min-w-0 text-xs">
                    <ResponsiveContainer
                        width="100%"
                        height="100%"
                        minWidth={0}
                        initialDimension={{ width: 600, height: 300 }}
                    >
                        <LineChart
                            accessibilityLayer
                            margin={{ top: 12, right: 12, bottom: 8, left: 0 }}
                        >
                            <CartesianGrid
                                vertical={false}
                                stroke="var(--border)"
                            />
                            <XAxis
                                dataKey="timestamp"
                                type="number"
                                scale="time"
                                domain={timeDomain}
                                allowDataOverflow
                                tickFormatter={(value: number) =>
                                    tickDate.format(value)
                                }
                                minTickGap={35}
                                stroke="var(--muted-foreground)"
                                tickLine={false}
                            />
                            <YAxis
                                allowDecimals={false}
                                width={58}
                                tickFormatter={(value: number) =>
                                    compact.format(value)
                                }
                                stroke="var(--muted-foreground)"
                                tickLine={false}
                                axisLine={false}
                            />
                            <Tooltip
                                content={
                                    <HistoryTooltip
                                        distributionLabels={distributionLabels}
                                    />
                                }
                                filterNull={false}
                                isAnimationActive={false}
                            />
                            {prepared.map((plot) => (
                                <Line
                                    key={plot.key}
                                    name={plot.label}
                                    data={plot.points}
                                    dataKey="downloads"
                                    type="stepAfter"
                                    connectNulls={false}
                                    isAnimationActive={false}
                                    stroke={historyStyle(plot.key).color}
                                    strokeDasharray={
                                        historyStyle(plot.key).dash
                                    }
                                    strokeWidth={2}
                                    activeDot={false}
                                    dot={(props) => {
                                        const point =
                                            props.payload as PlotPoint;
                                        if (!point.marker) return null;
                                        return (
                                            <circle
                                                key={point.at}
                                                cx={props.cx}
                                                cy={props.cy}
                                                r={4}
                                                fill={
                                                    historyStyle(plot.key).color
                                                }
                                                stroke="var(--card)"
                                                strokeWidth={1.5}
                                            />
                                        );
                                    }}
                                />
                            ))}
                        </LineChart>
                    </ResponsiveContainer>
                </div>
            ) : (
                <p
                    className="text-muted-foreground flex min-h-40 items-center justify-center rounded-lg border border-dashed p-6 text-center text-sm"
                    role="status"
                >
                    No complete values to plot in this range. Inspect the
                    plotted values for coverage and source times.
                </p>
            )}
            <ul
                aria-label="History series"
                className="flex flex-wrap gap-x-5 gap-y-2 text-xs"
            >
                {plots.map((plot) => (
                    <li
                        key={plot.key}
                        className="flex min-w-0 items-center gap-2"
                    >
                        <svg
                            aria-hidden="true"
                            width="24"
                            height="8"
                            className="shrink-0"
                        >
                            <line
                                x1="0"
                                x2="24"
                                y1="4"
                                y2="4"
                                stroke={historyStyle(plot.key).color}
                                strokeDasharray={historyStyle(plot.key).dash}
                                strokeWidth="2"
                            />
                        </svg>
                        <span className="wrap-anywhere">{plot.label}</span>
                    </li>
                ))}
            </ul>
            {plots.some((plot) => plot.sampling.mode !== 'exact') && (
                <p className="text-muted-foreground text-xs">
                    Long history is reduced for display. Counter corrections and
                    coverage gaps are retained; the table shows the returned
                    points.
                </p>
            )}
            <PlottedValues
                plots={plots}
                distributionLabels={distributionLabels}
            />
        </div>
    );
}
