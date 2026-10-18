import { Form, Head } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    ChevronRight,
    Clock,
    Download,
    ExternalLink,
    EyeOff,
    RotateCcw,
    TrendingUp,
} from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import DistributionVersionExclusionController from '@/actions/App/Http/Controllers/DistributionVersionExclusionController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { dashboard } from '@/routes';
import type {
    DashboardAnalytics,
    DashboardCoverageCount,
    DashboardDistribution,
    DashboardRemovedVersion,
    DashboardVersion,
    DistributionGrowthRange,
    HistoryRangeKey,
    TotalGrowthRange,
} from '@/types';

interface DashboardProps {
    analytics: DashboardAnalytics;
    historyRange: HistoryRangeKey;
}

const numberFormatter = new Intl.NumberFormat();
const growthFormatter = new Intl.NumberFormat(undefined, {
    signDisplay: 'exceptZero',
});
const dateFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatNumber(value: number | null): string {
    return value === null ? 'Pending' : numberFormatter.format(value);
}

function formatDate(value: string | null): string {
    if (value === null) {
        return 'Not collected yet';
    }

    return dateFormatter.format(new Date(value));
}

function formatGrowth(value: number | null): string {
    return value === null ? '—' : growthFormatter.format(value);
}

function totalGrowthNote(
    range: TotalGrowthRange,
    distributionCount: number,
): string {
    if (range.status === 'insufficient_history') {
        return 'Not enough history';
    }

    if (range.status === 'partial') {
        const readyCount =
            distributionCount - range.missingDistributionIds.length;

        return `Partial: ${readyCount} of ${distributionCount} listings`;
    }

    if (range.downloads !== null && range.downloads < 0) {
        return 'Includes a counter correction';
    }

    return 'All listings';
}

function formatLabel(value: string): string {
    return value
        .split(/[-_]/)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function formatList(values: string[], limit = 3): string {
    if (values.length <= limit) {
        return values.join(', ');
    }

    return `${values.slice(0, limit).join(', ')} +${values.length - limit}`;
}

function StatusBadge({ status }: { status: DashboardDistribution['status'] }) {
    if (status === 'current') {
        return (
            <Badge variant="secondary" className="gap-1.5">
                <CheckCircle2 className="size-3.5" />
                Current
            </Badge>
        );
    }

    if (status === 'stale') {
        return (
            <Badge
                variant="outline"
                className="text-muted-foreground gap-1.5"
                title="Not captured in the latest collection"
            >
                <Clock className="size-3.5" />
                Stale
            </Badge>
        );
    }

    return (
        <Badge
            variant="outline"
            className="text-muted-foreground gap-1.5"
            title="Never captured"
        >
            <AlertCircle className="size-3.5" />
            Missing
        </Badge>
    );
}

function VersionStatusBadge({
    status,
}: {
    status: DashboardVersion['status'];
}) {
    if (status === 'current') {
        return null;
    }

    return (
        <Badge
            variant="outline"
            className="text-muted-foreground gap-1.5"
            title="Not captured in the latest collection"
        >
            <AlertCircle className="size-3.5" />
            Missing
        </Badge>
    );
}

interface CoverageCardProps {
    label: string;
    count: DashboardCoverageCount;
}

function CoverageCard({ label, count }: CoverageCardProps) {
    return (
        <Card className="gap-3">
            <CardHeader className="pb-0">
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-3xl">
                    {count.captured}
                    <span className="text-muted-foreground">
                        /{count.available}
                    </span>
                </CardTitle>
                <p className="text-muted-foreground text-xs">
                    Captured in the latest collection
                </p>
            </CardHeader>
        </Card>
    );
}

interface GrowthSummaryProps {
    ranges: TotalGrowthRange[];
    distributionCount: number;
}

function GrowthSummary({ ranges, distributionCount }: GrowthSummaryProps) {
    return (
        <Card className="gap-4">
            <CardHeader className="pb-0">
                <CardTitle className="flex items-center gap-2">
                    <TrendingUp className="text-muted-foreground size-4" />
                    Recent growth
                </CardTitle>
                <CardDescription>
                    Download gains between stored snapshots, measured back from
                    the latest collection.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-3 gap-3 sm:gap-4">
                    {ranges.map((range) => (
                        <div
                            key={range.key}
                            className="min-w-0 rounded-lg border px-3 py-3 sm:px-4"
                        >
                            <dt className="text-muted-foreground text-xs font-medium">
                                {range.label}
                            </dt>
                            <dd
                                className={
                                    range.status === 'insufficient_history'
                                        ? 'text-muted-foreground mt-1 text-xl font-semibold sm:text-2xl'
                                        : 'mt-1 text-xl font-semibold sm:text-2xl'
                                }
                            >
                                {formatGrowth(range.downloads)}
                            </dd>
                            <dd className="text-muted-foreground mt-1 text-xs">
                                {totalGrowthNote(range, distributionCount)}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

function DistributionGrowth({ growth }: { growth: DistributionGrowthRange[] }) {
    return (
        <dl className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm">
            {growth.map((range) => {
                const isReady = range.status === 'ready';

                return (
                    <div
                        key={range.key}
                        className="flex items-baseline gap-1"
                        title={isReady ? undefined : 'Not enough history yet'}
                    >
                        <dt className="text-muted-foreground text-xs">
                            {range.label}
                        </dt>
                        <dd
                            className={
                                isReady
                                    ? 'font-semibold'
                                    : 'text-muted-foreground'
                            }
                        >
                            {formatGrowth(range.downloads)}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

const rowGridClassName =
    'grid gap-4 px-4 py-4 sm:px-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(6rem,0.7fr)_minmax(12rem,1.2fr)_minmax(9rem,1fr)_2.25rem] lg:items-center';

interface MetricColumnsProps {
    downloads: number | null;
    growth: DistributionGrowthRange[];
    capturedAt: string | null;
}

function MetricColumns({ downloads, growth, capturedAt }: MetricColumnsProps) {
    return (
        <>
            <div>
                <p className="text-muted-foreground text-xs font-medium">
                    Downloads
                </p>
                <p className="mt-1 text-sm font-semibold">
                    {formatNumber(downloads)}
                </p>
            </div>

            <div>
                <p className="text-muted-foreground text-xs font-medium">
                    Growth
                </p>
                <DistributionGrowth growth={growth} />
            </div>

            <div>
                <p className="text-muted-foreground text-xs font-medium">
                    Captured
                </p>
                <p className="mt-1 text-sm">{formatDate(capturedAt)}</p>
            </div>
        </>
    );
}

// Keep the expanded panel open and the scroll position after an action;
// the redirect back still reloads coverage and the version/file lists.
const exclusionFormOptions = { preserveState: true, preserveScroll: true };

function versionDetails(
    version: DashboardVersion | DashboardRemovedVersion,
): string {
    const details = [];

    if (
        version.versionNumber !== null &&
        version.versionNumber !== version.displayName
    ) {
        details.push(version.versionNumber);
    }

    if (version.loaders.length > 0) {
        details.push(version.loaders.map(formatLabel).join(', '));
    }

    if (version.gameVersions.length > 0) {
        details.push(`MC ${formatList(version.gameVersions)}`);
    }

    return details.join(' · ');
}

function VersionName({
    version,
    badge,
}: {
    version: DashboardVersion | DashboardRemovedVersion;
    badge: ReactNode;
}) {
    const details = versionDetails(version);

    return (
        <div className="min-w-0 pl-9">
            <div className="flex flex-wrap items-center gap-2">
                <h4 className="truncate text-sm font-medium">
                    {version.displayName}
                </h4>
                {badge}
            </div>
            {details !== '' && (
                <p
                    className="text-muted-foreground mt-1 text-xs"
                    title={version.gameVersions.join(', ')}
                >
                    {details}
                </p>
            )}
        </div>
    );
}

function RemoveVersionDialog({ version }: { version: DashboardVersion }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    className="text-muted-foreground size-9"
                    aria-label={`Remove ${version.displayName}`}
                    title="Remove"
                >
                    <EyeOff className="size-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Remove {version.displayName}?</DialogTitle>
                <DialogDescription>
                    This version/file was not captured in the latest collection.
                    Removing it hides it from the list and from version/file
                    coverage. Its snapshot history is kept, and it comes back
                    automatically if a later collection finds it. You can also
                    restore it from the removed list.
                </DialogDescription>

                <Form
                    {...DistributionVersionExclusionController.store.form(
                        version.id,
                    )}
                    options={exclusionFormOptions}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <InputError message={errors.version} />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button type="submit" disabled={processing}>
                                    Remove version/file
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function VersionRow({ version }: { version: DashboardVersion }) {
    return (
        <li className={`${rowGridClassName} border-t first:border-t-0`}>
            <VersionName
                version={version}
                badge={<VersionStatusBadge status={version.status} />}
            />

            <MetricColumns
                downloads={version.downloads}
                growth={version.growth}
                capturedAt={version.capturedAt}
            />

            {version.status === 'missing' ? (
                <RemoveVersionDialog version={version} />
            ) : (
                <div aria-hidden="true" className="hidden lg:block" />
            )}
        </li>
    );
}

function RemovedVersionRow({ version }: { version: DashboardRemovedVersion }) {
    return (
        <li className={`${rowGridClassName} border-t`}>
            <VersionName
                version={version}
                badge={
                    <Badge
                        variant="outline"
                        className="text-muted-foreground gap-1.5"
                    >
                        <EyeOff className="size-3.5" />
                        Removed
                    </Badge>
                }
            />

            <div>
                <p className="text-muted-foreground text-xs font-medium">
                    Downloads
                </p>
                <p className="text-muted-foreground mt-1 text-sm">
                    {formatNumber(version.downloads)}
                </p>
            </div>

            <div>
                <p className="text-muted-foreground text-xs font-medium">
                    Removed
                </p>
                <p className="mt-1 text-sm">{formatDate(version.excludedAt)}</p>
            </div>

            <div>
                <p className="text-muted-foreground text-xs font-medium">
                    Last captured
                </p>
                <p className="mt-1 text-sm">{formatDate(version.capturedAt)}</p>
            </div>

            <Form
                {...DistributionVersionExclusionController.destroy.form(
                    version.id,
                )}
                options={exclusionFormOptions}
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="outline"
                        size="icon"
                        className="text-muted-foreground size-9"
                        disabled={processing}
                        aria-label={`Restore ${version.displayName}`}
                        title="Restore"
                    >
                        <RotateCcw className="size-4" />
                    </Button>
                )}
            </Form>
        </li>
    );
}

function RemovedVersions({
    distribution,
}: {
    distribution: DashboardDistribution;
}) {
    const [showRemoved, setShowRemoved] = useState(false);
    const listId = useId();
    const removedCount = distribution.removedVersions.length;

    if (removedCount === 0) {
        return null;
    }

    return (
        <div className="border-t">
            <div className="px-4 py-2 pl-13 sm:px-6 sm:pl-15">
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground -ml-3"
                    aria-expanded={showRemoved}
                    aria-controls={listId}
                    onClick={() => setShowRemoved((value) => !value)}
                >
                    {showRemoved
                        ? `Hide removed (${removedCount})`
                        : `Show removed (${removedCount})`}
                </Button>
            </div>

            {showRemoved && (
                <ul
                    id={listId}
                    aria-label={`${distribution.name} removed versions/files`}
                >
                    {distribution.removedVersions.map((version) => (
                        <RemovedVersionRow key={version.id} version={version} />
                    ))}
                </ul>
            )}
        </div>
    );
}

interface DistributionRowProps {
    distribution: DashboardDistribution;
}

function DistributionRow({ distribution }: DistributionRowProps) {
    const [open, setOpen] = useState(false);
    const versionCount = distribution.versions.length;

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="border-t first:border-t-0"
        >
            <div className={rowGridClassName}>
                <div className="flex min-w-0 items-start gap-2">
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="-my-0.5 size-7 shrink-0"
                            aria-label={`Versions/files for ${distribution.name}`}
                        >
                            <ChevronRight
                                className={`size-4 transition-transform ${open ? 'rotate-90' : ''}`}
                            />
                        </Button>
                    </CollapsibleTrigger>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="truncate text-sm font-medium">
                                {distribution.name}
                            </h3>
                            <StatusBadge status={distribution.status} />
                        </div>
                        <p className="text-muted-foreground mt-1 text-xs">
                            {formatLabel(distribution.provider)} /{' '}
                            {formatLabel(distribution.loader)} ·{' '}
                            {versionCount === 1
                                ? '1 version/file'
                                : `${versionCount} versions/files`}
                        </p>
                    </div>
                </div>

                <MetricColumns
                    downloads={distribution.downloads}
                    growth={distribution.growth}
                    capturedAt={distribution.capturedAt}
                />

                <a
                    href={distribution.listingUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="text-muted-foreground hover:bg-accent hover:text-accent-foreground inline-flex size-9 items-center justify-center rounded-md border transition-colors"
                    aria-label={`Open ${distribution.name} listing`}
                >
                    <ExternalLink className="size-4" />
                </a>
            </div>

            <CollapsibleContent className="bg-muted/30 border-t">
                {versionCount === 0 ? (
                    <p className="text-muted-foreground px-4 py-4 pl-13 text-sm sm:px-6 sm:pl-15">
                        No versions/files collected yet.
                    </p>
                ) : (
                    <ul aria-label={`${distribution.name} versions/files`}>
                        {distribution.versions.map((version) => (
                            <VersionRow key={version.id} version={version} />
                        ))}
                    </ul>
                )}

                <RemovedVersions distribution={distribution} />
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function Dashboard({ analytics }: DashboardProps) {
    return (
        <>
            <Head title="Analytics Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-normal">
                            Distribution analytics
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Latest snapshots for the active launch
                            distributions.
                        </p>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Last updated: {formatDate(analytics.lastUpdatedAt)}
                    </p>
                </div>

                {!analytics.hasSnapshots && (
                    <Card className="gap-3">
                        <CardHeader>
                            <CardTitle>No snapshots collected</CardTitle>
                            <CardDescription>
                                Dashboard metrics will appear after the first
                                successful provider collection.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {analytics.hasMissingSnapshots && analytics.hasSnapshots && (
                    <div className="bg-muted/35 text-muted-foreground rounded-lg border px-4 py-3 text-sm">
                        Some active distributions are missing snapshots. Totals
                        include only distributions with stored data.
                    </div>
                )}

                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <Card className="gap-3">
                        <CardHeader className="pb-0">
                            <CardDescription>Total downloads</CardDescription>
                            <CardTitle className="flex items-center gap-2 text-3xl">
                                <Download className="text-muted-foreground size-6" />
                                {numberFormatter.format(
                                    analytics.totalDownloads,
                                )}
                            </CardTitle>
                        </CardHeader>
                    </Card>

                    <CoverageCard
                        label="Distribution coverage"
                        count={analytics.coverage.distributions}
                    />

                    <CoverageCard
                        label="Version/file coverage"
                        count={analytics.coverage.versions}
                    />
                </div>

                {analytics.hasSnapshots && (
                    <GrowthSummary
                        ranges={analytics.growth.ranges}
                        distributionCount={analytics.distributions.length}
                    />
                )}

                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="py-5">
                        <CardTitle>Distribution snapshots</CardTitle>
                        <CardDescription>
                            Downloads, recent growth, and capture times for each
                            active listing. Expand a listing to see its
                            versions/files.
                        </CardDescription>
                        <p className="text-muted-foreground text-xs">
                            Version/file downloads may not add up to the
                            distribution total.
                        </p>
                    </CardHeader>
                    <CardContent className="px-0">
                        {analytics.distributions.map((distribution) => (
                            <DistributionRow
                                key={distribution.id}
                                distribution={distribution}
                            />
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
