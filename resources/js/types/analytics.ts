export type DashboardDistributionStatus = 'current' | 'stale' | 'missing';

export type DashboardVersionStatus = 'current' | 'missing';

export type GrowthRangeKey = 'day' | 'week' | 'month';

export type GrowthRangeHours = 24 | 168 | 720;

export type DistributionGrowthStatus = 'ready' | 'insufficient_history';

export type TotalGrowthStatus = DistributionGrowthStatus | 'partial';

export interface DistributionGrowthRange {
    key: GrowthRangeKey;
    label: string;
    hours: GrowthRangeHours;
    downloads: number | null;
    status: DistributionGrowthStatus;
    capturedAt: string | null;
    baselineAt: string | null;
}

export interface TotalGrowthRange {
    key: GrowthRangeKey;
    label: string;
    hours: GrowthRangeHours;
    downloads: number | null;
    status: TotalGrowthStatus;
    capturedAt: string | null;
    rangeStartedAt: string | null;
    missingDistributionIds: number[];
}

export interface DashboardGrowth {
    ranges: TotalGrowthRange[];
}

export interface DashboardDistribution {
    id: number;
    provider: string;
    name: string;
    loader: string;
    listingUrl: string;
    downloads: number | null;
    followers: number | null;
    likes: number | null;
    capturedAt: string | null;
    status: DashboardDistributionStatus;
    growth: DistributionGrowthRange[];
    versions: DashboardVersion[];
    removedVersions: DashboardRemovedVersion[];
}

export interface DashboardVersion {
    id: number;
    displayName: string;
    versionNumber: string | null;
    loaders: string[];
    gameVersions: string[];
    publishedAt: string | null;
    downloads: number | null;
    capturedAt: string | null;
    status: DashboardVersionStatus;
    growth: DistributionGrowthRange[];
}

export interface DashboardRemovedVersion {
    id: number;
    displayName: string;
    versionNumber: string | null;
    loaders: string[];
    gameVersions: string[];
    publishedAt: string | null;
    downloads: number | null;
    capturedAt: string | null;
    excludedAt: string;
}

export interface DashboardLatestCollection {
    capturedAt: string | null;
    windowStartedAt: string | null;
}

export interface DashboardCoverageCount {
    captured: number;
    available: number;
}

export interface DashboardCoverage {
    distributions: DashboardCoverageCount;
    versions: DashboardCoverageCount;
}

export interface DashboardAnalytics {
    totalDownloads: number;
    lastUpdatedAt: string | null;
    hasSnapshots: boolean;
    hasMissingSnapshots: boolean;
    growth: DashboardGrowth;
    latestCollection: DashboardLatestCollection;
    coverage: DashboardCoverage;
    distributions: DashboardDistribution[];
}

export type HistoryRangeKey = '7d' | '30d' | '90d' | 'all';

export type HistorySeriesKey = `distribution:${number}` | `version:${number}`;

export type HistoryStatus =
    | 'ready'
    | 'single_point'
    | 'outside_range'
    | 'never_collected';

export interface HistorySampling {
    mode: 'exact' | 'min_max';
    bucketSeconds: number | null;
    originalObservationCount: number;
    returnedObservationCount: number;
    returnedPointCount: number;
}

export interface HistoryPoint {
    at: string;
    downloads: number | null;
    sourceCapturedAt: string | null;
    kind: 'observed' | 'carried' | 'gap';
    gapReason: 'not_yet_observed' | 'expired' | null;
}

export interface HistorySeries {
    key: HistorySeriesKey;
    id: number;
    distributionId: number;
    label: string;
    historyStatus: HistoryStatus;
    observationCount: number;
    points: HistoryPoint[];
    sampling: HistorySampling;
}

export interface HistoryContributor {
    distributionId: number;
    downloads: number | null;
    sourceCapturedAt: string | null;
    status: 'observed' | 'carried' | 'stale' | 'unknown';
}

export interface HistoryTotalPoint {
    at: string;
    downloads: number | null;
    knownDownloads: number | null;
    status: 'complete' | 'partial' | 'unavailable';
    contributors: HistoryContributor[];
}

export interface HistoryTotal {
    key: 'total';
    points: HistoryTotalPoint[];
    sampling: HistorySampling;
}

export interface HistoryMeta {
    range: HistoryRangeKey;
    anchorAt: string | null;
    domain: { startedAt: string | null; endedAt: string | null };
    stalenessSeconds: 43200;
    budget: { pointTarget: 20000; byteTarget: 3145728; exceeded: boolean };
}

export interface AggregateHistory extends HistoryMeta {
    scope: 'aggregate';
    series: HistorySeries[];
    total: HistoryTotal;
}

export interface DistributionHistory extends HistoryMeta {
    scope: 'versions';
    distributionId: number;
    series: HistorySeries[];
}

export type HistoryResponse = AggregateHistory | DistributionHistory;
