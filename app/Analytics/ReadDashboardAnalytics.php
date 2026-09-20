<?php

namespace App\Analytics;

use App\Models\Distribution;
use App\Models\MetricSnapshot;
use Illuminate\Support\Carbon;

class ReadDashboardAnalytics
{
    /**
     * @return array{
     *     totalDownloads: int,
     *     lastUpdatedAt: string|null,
     *     hasSnapshots: bool,
     *     hasMissingSnapshots: bool,
     *     distributions: list<array{
     *         id: int,
     *         provider: string,
     *         name: string,
     *         loader: string,
     *         listingUrl: string,
     *         downloads: int|null,
     *         followers: int|null,
     *         likes: int|null,
     *         capturedAt: string|null,
     *         status: 'current'|'missing'
     *     }>
     * }
     */
    public function __invoke(): array
    {
        $distributions = Distribution::query()
            ->where('active', true)
            ->with([
                'metricSnapshots' => fn ($query) => $query
                    ->orderByDesc('captured_at')
                    ->orderByDesc('id'),
            ])
            ->get()
            ->sortBy(fn (Distribution $distribution): int => $this->displayOrder($distribution))
            ->values();

        $totalDownloads = 0;
        $lastUpdatedAt = null;
        $hasSnapshots = false;
        $hasMissingSnapshots = false;
        $dashboardDistributions = [];

        foreach ($distributions as $distribution) {
            /** @var MetricSnapshot|null $snapshot */
            $snapshot = $distribution->metricSnapshots->first();

            if ($snapshot === null) {
                $hasMissingSnapshots = true;
            } else {
                $hasSnapshots = true;
                $totalDownloads += $snapshot->downloads;
                $lastUpdatedAt = $this->newerTimestamp($lastUpdatedAt, $snapshot->captured_at);
            }

            $dashboardDistributions[] = $this->serializeDistribution($distribution, $snapshot);
        }

        return [
            'totalDownloads' => $totalDownloads,
            'lastUpdatedAt' => $lastUpdatedAt?->toJSON(),
            'hasSnapshots' => $hasSnapshots,
            'hasMissingSnapshots' => $hasMissingSnapshots,
            'distributions' => $dashboardDistributions,
        ];
    }

    private function displayOrder(Distribution $distribution): int
    {
        return match ("{$distribution->provider}:{$distribution->loader}") {
            'modrinth:combined' => 10,
            'curseforge:fabric' => 20,
            'curseforge:paper' => 30,
            default => 100,
        };
    }

    private function newerTimestamp(?Carbon $current, Carbon $candidate): Carbon
    {
        if ($current === null || $candidate->greaterThan($current)) {
            return $candidate;
        }

        return $current;
    }

    /**
     * @return array{
     *     id: int,
     *     provider: string,
     *     name: string,
     *     loader: string,
     *     listingUrl: string,
     *     downloads: int|null,
     *     followers: int|null,
     *     likes: int|null,
     *     capturedAt: string|null,
     *     status: 'current'|'missing'
     * }
     */
    private function serializeDistribution(Distribution $distribution, ?MetricSnapshot $snapshot): array
    {
        return [
            'id' => $distribution->id,
            'provider' => $distribution->provider,
            'name' => $distribution->name,
            'loader' => $distribution->loader,
            'listingUrl' => $distribution->listing_url,
            'downloads' => $snapshot?->downloads,
            'followers' => $snapshot?->followers,
            'likes' => $snapshot?->likes,
            'capturedAt' => $snapshot?->captured_at->toJSON(),
            'status' => $snapshot === null ? 'missing' : 'current',
        ];
    }
}
