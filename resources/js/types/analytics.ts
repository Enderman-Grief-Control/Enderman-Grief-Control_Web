export type DashboardDistributionStatus = 'current' | 'missing';

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
}

export interface DashboardAnalytics {
    totalDownloads: number;
    lastUpdatedAt: string | null;
    hasSnapshots: boolean;
    hasMissingSnapshots: boolean;
    distributions: DashboardDistribution[];
}
