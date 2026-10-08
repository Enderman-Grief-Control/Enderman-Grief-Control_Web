<?php

namespace App\Analytics;

use App\Models\Distribution;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ReadDashboardAnalytics
{
    private const GROWTH_RANGES = [
        ['key' => 'day', 'label' => '24h', 'hours' => 24],
        ['key' => 'week', 'label' => '7d', 'hours' => 168],
        ['key' => 'month', 'label' => '30d', 'hours' => 720],
    ];

    /**
     * @return array{
     *     totalDownloads: int,
     *     lastUpdatedAt: string|null,
     *     hasSnapshots: bool,
     *     hasMissingSnapshots: bool,
     *     growth: array{
     *         ranges: list<array{
     *             key: 'day'|'week'|'month',
     *             label: string,
     *             hours: 24|168|720,
     *             downloads: int|null,
     *             status: 'ready'|'partial'|'insufficient_history',
     *             capturedAt: string|null,
     *             rangeStartedAt: string|null,
     *             missingDistributionIds: list<int>
     *         }>
     *     },
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
     *         status: 'current'|'missing',
     *         growth: list<array{
     *             key: 'day'|'week'|'month',
     *             label: string,
     *             hours: 24|168|720,
     *             downloads: int|null,
     *             status: 'ready'|'insufficient_history',
     *             capturedAt: string|null,
     *             baselineAt: string|null
     *         }>
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
        $latestSnapshots = [];

        foreach ($distributions as $distribution) {
            /** @var MetricSnapshot|null $snapshot */
            $snapshot = $distribution->metricSnapshots->first();
            $latestSnapshots[$distribution->id] = $snapshot;

            if ($snapshot === null) {
                $hasMissingSnapshots = true;
            } else {
                $hasSnapshots = true;
                $totalDownloads += $snapshot->downloads;
                $lastUpdatedAt = $this->newerTimestamp($lastUpdatedAt, $snapshot->captured_at);
            }

            $dashboardDistributions[] = $this->serializeDistribution($distribution, $snapshot);
        }

        $anchorAt = $lastUpdatedAt;
        $distributionGrowth = [];

        foreach ($distributions as $distribution) {
            $distributionGrowth[$distribution->id] = $this->distributionGrowth(
                $distribution,
                $latestSnapshots[$distribution->id],
                $anchorAt,
            );
        }

        foreach ($dashboardDistributions as &$dashboardDistribution) {
            $dashboardDistribution['growth'] = $distributionGrowth[$dashboardDistribution['id']];
        }

        unset($dashboardDistribution);

        return [
            'totalDownloads' => $totalDownloads,
            'lastUpdatedAt' => $lastUpdatedAt?->toJSON(),
            'hasSnapshots' => $hasSnapshots,
            'hasMissingSnapshots' => $hasMissingSnapshots,
            'growth' => [
                'ranges' => $this->totalGrowth($distributions->all(), $distributionGrowth, $anchorAt),
            ],
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

    private function newerTimestamp(?CarbonInterface $current, CarbonInterface $candidate): CarbonInterface
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

    /**
     * @return list<array{
     *     key: 'day'|'week'|'month',
     *     label: string,
     *     hours: 24|168|720,
     *     downloads: int|null,
     *     status: 'ready'|'insufficient_history',
     *     capturedAt: string|null,
     *     baselineAt: string|null
     * }>
     */
    private function distributionGrowth(
        Distribution $distribution,
        ?MetricSnapshot $latestSnapshot,
        ?CarbonInterface $anchorAt,
    ): array {
        return array_map(
            function (array $range) use ($distribution, $latestSnapshot, $anchorAt): array {
                if ($latestSnapshot === null || $anchorAt === null) {
                    return $this->serializeDistributionGrowthRange($range);
                }

                $rangeStartedAt = CarbonImmutable::instance($anchorAt)->subHours($range['hours']);
                $baselineSnapshot = $this->baselineSnapshot($distribution, $rangeStartedAt);

                if ($baselineSnapshot === null) {
                    return $this->serializeDistributionGrowthRange($range, latestSnapshot: $latestSnapshot);
                }

                return $this->serializeDistributionGrowthRange(
                    $range,
                    downloads: $latestSnapshot->downloads - $baselineSnapshot->downloads,
                    status: 'ready',
                    latestSnapshot: $latestSnapshot,
                    baselineSnapshot: $baselineSnapshot,
                );
            },
            self::GROWTH_RANGES,
        );
    }

    private function baselineSnapshot(Distribution $distribution, CarbonInterface $rangeStartedAt): ?MetricSnapshot
    {
        /** @var MetricSnapshot|null $snapshot */
        $snapshot = $distribution->metricSnapshots
            ->first(fn (MetricSnapshot $snapshot): bool => $snapshot->captured_at->lessThanOrEqualTo($rangeStartedAt));

        return $snapshot;
    }

    /**
     * @param  array{key: 'day'|'week'|'month', label: string, hours: 24|168|720}  $range
     * @param  'ready'|'insufficient_history'  $status
     * @return array{
     *     key: 'day'|'week'|'month',
     *     label: string,
     *     hours: 24|168|720,
     *     downloads: int|null,
     *     status: 'ready'|'insufficient_history',
     *     capturedAt: string|null,
     *     baselineAt: string|null
     * }
     */
    private function serializeDistributionGrowthRange(
        array $range,
        ?int $downloads = null,
        string $status = 'insufficient_history',
        ?MetricSnapshot $latestSnapshot = null,
        ?MetricSnapshot $baselineSnapshot = null,
    ): array {
        return [
            'key' => $range['key'],
            'label' => $range['label'],
            'hours' => $range['hours'],
            'downloads' => $downloads,
            'status' => $status,
            'capturedAt' => $latestSnapshot?->captured_at->toJSON(),
            'baselineAt' => $baselineSnapshot?->captured_at->toJSON(),
        ];
    }

    /**
     * @param  array<int, Distribution>  $distributions
     * @param  array<int, list<array{
     *     key: 'day'|'week'|'month',
     *     label: string,
     *     hours: 24|168|720,
     *     downloads: int|null,
     *     status: 'ready'|'insufficient_history',
     *     capturedAt: string|null,
     *     baselineAt: string|null
     * }>>  $distributionGrowth
     * @return list<array{
     *     key: 'day'|'week'|'month',
     *     label: string,
     *     hours: 24|168|720,
     *     downloads: int|null,
     *     status: 'ready'|'partial'|'insufficient_history',
     *     capturedAt: string|null,
     *     rangeStartedAt: string|null,
     *     missingDistributionIds: list<int>
     * }>
     */
    private function totalGrowth(array $distributions, array $distributionGrowth, ?CarbonInterface $anchorAt): array
    {
        return array_map(
            function (array $range) use ($distributions, $distributionGrowth, $anchorAt): array {
                $downloads = 0;
                $readyCount = 0;
                $missingDistributionIds = [];

                foreach ($distributions as $distribution) {
                    $growthRange = $this->findGrowthRange($distributionGrowth[$distribution->id], $range['key']);

                    if ($growthRange['status'] === 'ready') {
                        $readyCount++;
                        $downloads += $growthRange['downloads'];

                        continue;
                    }

                    $missingDistributionIds[] = $distribution->id;
                }

                $status = match (true) {
                    $readyCount === 0 => 'insufficient_history',
                    $readyCount < count($distributions) => 'partial',
                    default => 'ready',
                };

                return [
                    'key' => $range['key'],
                    'label' => $range['label'],
                    'hours' => $range['hours'],
                    'downloads' => $readyCount === 0 ? null : $downloads,
                    'status' => $status,
                    'capturedAt' => $anchorAt?->toJSON(),
                    'rangeStartedAt' => $anchorAt === null
                        ? null
                        : CarbonImmutable::instance($anchorAt)->subHours($range['hours'])->toJSON(),
                    'missingDistributionIds' => $missingDistributionIds,
                ];
            },
            self::GROWTH_RANGES,
        );
    }

    /**
     * @param  list<array{
     *     key: 'day'|'week'|'month',
     *     label: string,
     *     hours: 24|168|720,
     *     downloads: int|null,
     *     status: 'ready'|'insufficient_history',
     *     capturedAt: string|null,
     *     baselineAt: string|null
     * }>  $growthRanges
     * @return array{
     *     key: 'day'|'week'|'month',
     *     label: string,
     *     hours: 24|168|720,
     *     downloads: int|null,
     *     status: 'ready'|'insufficient_history',
     *     capturedAt: string|null,
     *     baselineAt: string|null
     * }
     */
    private function findGrowthRange(array $growthRanges, string $key): array
    {
        foreach ($growthRanges as $growthRange) {
            if ($growthRange['key'] === $key) {
                return $growthRange;
            }
        }

        throw new \LogicException("Growth range [{$key}] was not calculated.");
    }
}
