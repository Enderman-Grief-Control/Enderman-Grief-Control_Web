<?php

namespace App\Analytics;

use App\Models\DistributionVersion;
use App\Models\DistributionVersionSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Version/file rows for the dashboard, read for one latest collection.
 *
 * Snapshot history is never hydrated: each query selects at most one snapshot
 * per version/file (the newest one, optionally at or before a cutoff), so the
 * cost scales with the number of versions/files rather than their history.
 *
 * @phpstan-type VersionGrowthRange array{
 *     key: 'day'|'week'|'month',
 *     label: string,
 *     hours: 24|168|720,
 *     downloads: int|null,
 *     status: 'ready'|'insufficient_history',
 *     capturedAt: string|null,
 *     baselineAt: string|null
 * }
 * @phpstan-type DashboardVersion array{
 *     id: int,
 *     displayName: string,
 *     versionNumber: string|null,
 *     loaders: list<string>,
 *     gameVersions: list<string>,
 *     publishedAt: string|null,
 *     downloads: int|null,
 *     capturedAt: string|null,
 *     status: 'current'|'missing',
 *     growth: list<VersionGrowthRange>
 * }
 * @phpstan-type DashboardRemovedVersion array{
 *     id: int,
 *     displayName: string,
 *     versionNumber: string|null,
 *     loaders: list<string>,
 *     gameVersions: list<string>,
 *     publishedAt: string|null,
 *     downloads: int|null,
 *     capturedAt: string|null,
 *     excludedAt: string
 * }
 */
class ReadDistributionVersionAnalytics
{
    /**
     * @param  list<int>  $distributionIds  active distributions only
     * @return array{
     *     versions: array<int, list<DashboardVersion>>,
     *     removedVersions: array<int, list<DashboardRemovedVersion>>,
     *     coverage: array{captured: int, available: int}
     * }
     */
    public function __invoke(array $distributionIds, LatestCollection $latestCollection): array
    {
        $versions = DistributionVersion::query()->whereIn('distribution_id', $distributionIds)->get()->toBase();
        [$availableVersions, $removedVersions] = $versions
            ->partition(fn (DistributionVersion $version): bool => $version->isAvailable());

        $latestSnapshots = $this->newestSnapshots($this->ids($versions));
        $baselineSnapshots = $this->baselineSnapshots(
            $this->ids($availableVersions),
            $latestCollection->capturedAt,
        );

        $grouped = array_fill_keys($distributionIds, []);
        $removed = array_fill_keys($distributionIds, []);
        $captured = 0;
        $available = 0;

        foreach ($this->sortAvailable($availableVersions) as $version) {
            $row = $this->serializeVersion(
                $version,
                $latestSnapshots->get($version->id),
                $baselineSnapshots,
                $latestCollection,
            );

            $available++;

            if ($row['status'] === 'current') {
                $captured++;
            }

            $grouped[$version->distribution_id][] = $row;
        }

        foreach ($this->sortRemoved($removedVersions) as $version) {
            $removed[$version->distribution_id][] = $this->serializeRemovedVersion(
                $version,
                $latestSnapshots->get($version->id),
            );
        }

        return [
            'versions' => $grouped,
            'removedVersions' => $removed,
            'coverage' => ['captured' => $captured, 'available' => $available],
        ];
    }

    /**
     * @param  Collection<int, DistributionVersion>  $versions
     * @return list<int>
     */
    private function ids(Collection $versions): array
    {
        return array_values($versions->map(fn (DistributionVersion $version): int => $version->id)->all());
    }

    /**
     * Newest snapshot per version/file, optionally at or before a cutoff.
     * Ties on captured_at go to the highest id.
     *
     * @param  list<int>  $versionIds
     * @return Collection<int, DistributionVersionSnapshot>
     */
    private function newestSnapshots(array $versionIds, ?CarbonInterface $cutoff = null): Collection
    {
        if ($versionIds === []) {
            return new Collection;
        }

        return DistributionVersionSnapshot::query()
            ->from('distribution_version_snapshots as snapshot')
            ->select('snapshot.*')
            ->whereIn('snapshot.distribution_version_id', $versionIds)
            ->when($cutoff !== null, fn (Builder $query) => $query->where('snapshot.captured_at', '<=', $cutoff))
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->selectRaw('1')
                ->from('distribution_version_snapshots as newer')
                ->whereColumn('newer.distribution_version_id', 'snapshot.distribution_version_id')
                ->when($cutoff !== null, fn (QueryBuilder $query) => $query->where('newer.captured_at', '<=', $cutoff))
                ->where(fn (QueryBuilder $query) => $query
                    ->whereColumn('newer.captured_at', '>', 'snapshot.captured_at')
                    ->orWhere(fn (QueryBuilder $query) => $query
                        ->whereColumn('newer.captured_at', 'snapshot.captured_at')
                        ->whereColumn('newer.id', '>', 'snapshot.id')
                    )
                )
            )
            ->get()
            ->keyBy('distribution_version_id')
            ->toBase();
    }

    /**
     * @param  list<int>  $versionIds
     * @return array<string, Collection<int, DistributionVersionSnapshot>>
     */
    private function baselineSnapshots(array $versionIds, ?CarbonImmutable $anchorAt): array
    {
        $baselines = [];

        foreach (ReadDashboardAnalytics::GROWTH_RANGES as $range) {
            $baselines[$range['key']] = $anchorAt === null
                ? new Collection
                : $this->newestSnapshots($versionIds, $anchorAt->subHours($range['hours']));
        }

        return $baselines;
    }

    /**
     * @param  Collection<int, DistributionVersion>  $versions
     * @return Collection<int, DistributionVersion>
     */
    private function sortAvailable(Collection $versions): Collection
    {
        return $versions->sort(function (DistributionVersion $a, DistributionVersion $b): int {
            if ($a->published_at === null || $b->published_at === null) {
                $nullOrder = ($a->published_at === null) <=> ($b->published_at === null);

                if ($nullOrder !== 0) {
                    return $nullOrder;
                }
            } elseif (! $a->published_at->equalTo($b->published_at)) {
                return $b->published_at <=> $a->published_at;
            }

            return $b->id <=> $a->id;
        });
    }

    /**
     * @param  Collection<int, DistributionVersion>  $versions
     * @return Collection<int, DistributionVersion>
     */
    private function sortRemoved(Collection $versions): Collection
    {
        return $versions->sort(fn (DistributionVersion $a, DistributionVersion $b): int => [$b->excluded_at, $b->id] <=> [$a->excluded_at, $a->id]);
    }

    /**
     * @param  array<string, Collection<int, DistributionVersionSnapshot>>  $baselineSnapshots
     * @return DashboardVersion
     */
    private function serializeVersion(
        DistributionVersion $version,
        ?DistributionVersionSnapshot $latestSnapshot,
        array $baselineSnapshots,
        LatestCollection $latestCollection,
    ): array {
        return [
            ...$this->serializeIdentity($version, $latestSnapshot),
            'status' => $latestCollection->contains($latestSnapshot?->captured_at) ? 'current' : 'missing',
            'growth' => array_map(
                fn (array $range): array => $this->serializeGrowthRange(
                    $range,
                    $latestSnapshot,
                    $latestSnapshot === null ? null : $baselineSnapshots[$range['key']]->get($version->id),
                ),
                ReadDashboardAnalytics::GROWTH_RANGES,
            ),
        ];
    }

    /**
     * @return DashboardRemovedVersion
     */
    private function serializeRemovedVersion(DistributionVersion $version, ?DistributionVersionSnapshot $latestSnapshot): array
    {
        /** @var CarbonInterface $excludedAt */
        $excludedAt = $version->excluded_at;

        return [
            ...$this->serializeIdentity($version, $latestSnapshot),
            'excludedAt' => $excludedAt->toJSON(),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     displayName: string,
     *     versionNumber: string|null,
     *     loaders: list<string>,
     *     gameVersions: list<string>,
     *     publishedAt: string|null,
     *     downloads: int|null,
     *     capturedAt: string|null
     * }
     */
    private function serializeIdentity(DistributionVersion $version, ?DistributionVersionSnapshot $latestSnapshot): array
    {
        return [
            'id' => $version->id,
            'displayName' => $version->display_name,
            'versionNumber' => $version->version_number,
            'loaders' => array_values($version->loaders),
            'gameVersions' => array_values($version->game_versions),
            'publishedAt' => $version->published_at?->toJSON(),
            'downloads' => $latestSnapshot?->downloads,
            'capturedAt' => $latestSnapshot?->captured_at->toJSON(),
        ];
    }

    /**
     * Same rules as distribution growth: newest snapshot minus the newest
     * snapshot at or before the range start. No baseline means insufficient
     * history; a zero baseline is never inferred from published_at.
     *
     * @param  array{key: 'day'|'week'|'month', label: string, hours: 24|168|720}  $range
     * @return VersionGrowthRange
     */
    private function serializeGrowthRange(
        array $range,
        ?DistributionVersionSnapshot $latestSnapshot,
        ?DistributionVersionSnapshot $baselineSnapshot,
    ): array {
        $ready = $latestSnapshot !== null && $baselineSnapshot !== null;

        return [
            'key' => $range['key'],
            'label' => $range['label'],
            'hours' => $range['hours'],
            'downloads' => $ready ? $latestSnapshot->downloads - $baselineSnapshot->downloads : null,
            'status' => $ready ? 'ready' : 'insufficient_history',
            'capturedAt' => $latestSnapshot?->captured_at->toJSON(),
            'baselineAt' => $baselineSnapshot?->captured_at->toJSON(),
        ];
    }
}
