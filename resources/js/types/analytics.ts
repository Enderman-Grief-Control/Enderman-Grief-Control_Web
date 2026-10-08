export type DashboardDistributionStatus = 'current' | 'missing';

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
}

export interface DashboardAnalytics {
    totalDownloads: number;
    lastUpdatedAt: string | null;
    hasSnapshots: boolean;
    hasMissingSnapshots: boolean;
    growth: DashboardGrowth;
    distributions: DashboardDistribution[];
}
