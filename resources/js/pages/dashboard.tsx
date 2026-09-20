import { Head } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Download, ExternalLink } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import type { DashboardAnalytics, DashboardDistribution } from '@/types';

interface DashboardProps {
    analytics: DashboardAnalytics;
}

const numberFormatter = new Intl.NumberFormat();
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

function formatLabel(value: string): string {
    return value
        .split(/[-_]/)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
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

    return (
        <Badge variant="outline" className="gap-1.5 text-muted-foreground">
            <AlertCircle className="size-3.5" />
            Missing
        </Badge>
    );
}

function DistributionRow({distribution}: {distribution: DashboardDistribution;}) {
    return (
        <div className="grid gap-4 border-t px-4 py-4 first:border-t-0 sm:grid-cols-[minmax(0,1.35fr)_minmax(7rem,0.8fr)_minmax(9rem,1fr)_auto] sm:items-center sm:px-6">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="truncate text-sm font-medium">
                        {distribution.name}
                    </h3>
                    <StatusBadge status={distribution.status} />
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                    {formatLabel(distribution.provider)} /{' '}
                    {formatLabel(distribution.loader)}
                </p>
            </div>

            <div>
                <p className="text-xs font-medium text-muted-foreground">
                    Downloads
                </p>
                <p className="mt-1 text-sm font-semibold">
                    {formatNumber(distribution.downloads)}
                </p>
            </div>

            <div>
                <p className="text-xs font-medium text-muted-foreground">
                    Captured
                </p>
                <p className="mt-1 text-sm">{formatDate(distribution.capturedAt)}</p>
            </div>

            <a
                href={distribution.listingUrl}
                target="_blank"
                rel="noreferrer"
                className="inline-flex size-9 items-center justify-center rounded-md border text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                aria-label={`Open ${distribution.name} listing`}
            >
                <ExternalLink className="size-4" />
            </a>
        </div>
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
                        <p className="mt-1 text-sm text-muted-foreground">
                            Latest snapshots for the active launch
                            distributions.
                        </p>
                    </div>
                    <p className="text-sm text-muted-foreground">
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
                    <div className="rounded-lg border bg-muted/35 px-4 py-3 text-sm text-muted-foreground">
                        Some active distributions are missing snapshots. Totals
                        include only distributions with stored data.
                    </div>
                )}

                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <Card className="gap-3">
                        <CardHeader className="pb-0">
                            <CardDescription>Total downloads</CardDescription>
                            <CardTitle className="flex items-center gap-2 text-3xl">
                                <Download className="size-6 text-muted-foreground" />
                                {numberFormatter.format(analytics.totalDownloads)}
                            </CardTitle>
                        </CardHeader>
                    </Card>

                    <Card className="gap-3">
                        <CardHeader className="pb-0">
                            <CardDescription>Active distributions</CardDescription>
                            <CardTitle className="text-3xl">
                                {analytics.distributions.length}
                            </CardTitle>
                        </CardHeader>
                    </Card>

                    <Card className="gap-3">
                        <CardHeader className="pb-0">
                            <CardDescription>Collection coverage</CardDescription>
                            <CardTitle className="text-3xl">
                                {
                                    analytics.distributions.filter(
                                        (distribution) =>
                                            distribution.status === 'current',
                                    ).length
                                }
                                <span className="text-muted-foreground">
                                    /{analytics.distributions.length}
                                </span>
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>

                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="py-5">
                        <CardTitle>Distribution snapshots</CardTitle>
                        <CardDescription>
                            Downloads and capture times for each active listing.
                        </CardDescription>
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
