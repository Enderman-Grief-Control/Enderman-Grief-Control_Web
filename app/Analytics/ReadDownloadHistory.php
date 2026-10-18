<?php

namespace App\Analytics;

use App\Models\Distribution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stored cumulative downloads only. No providers, writes, or summary semantics.
 *
 * @phpstan-type HistorySampling array{
 *     mode: 'exact'|'min_max', bucketSeconds: int|null,
 *     originalObservationCount: int, returnedObservationCount: int, returnedPointCount: int
 * }
 * @phpstan-type HistoryPoint array{
 *     at: string, downloads: int|null, sourceCapturedAt: string|null,
 *     kind: 'observed'|'carried'|'gap', gapReason: 'not_yet_observed'|'expired'|null
 * }
 * @phpstan-type HistorySeries array{
 *     key: string, id: int, distributionId: int, label: string,
 *     historyStatus: 'ready'|'single_point'|'outside_range'|'never_collected',
 *     observationCount: int, points: list<HistoryPoint>, sampling: HistorySampling
 * }
 * @phpstan-type HistoryContributor array{
 *     distributionId: int, downloads: int|null, sourceCapturedAt: string|null,
 *     status: 'observed'|'carried'|'stale'|'unknown'
 * }
 * @phpstan-type HistoryTotalPoint array{
 *     at: string, downloads: int|null, knownDownloads: int|null,
 *     status: 'complete'|'partial'|'unavailable', contributors: list<HistoryContributor>
 * }
 * @phpstan-type HistoryTotal array{
 *     key: 'total', points: list<HistoryTotalPoint>, sampling: HistorySampling
 * }
 * @phpstan-type AggregateHistory array{
 *     range: '7d'|'30d'|'90d'|'all', anchorAt: string|null,
 *     domain: array{startedAt: string|null, endedAt: string|null}, stalenessSeconds: 43200,
 *     budget: array{pointTarget: 20000, byteTarget: 3145728, exceeded: bool},
 *     scope: 'aggregate', series: list<HistorySeries>, total: HistoryTotal
 * }
 * @phpstan-type DistributionHistory array{
 *     range: '7d'|'30d'|'90d'|'all', anchorAt: string|null,
 *     domain: array{startedAt: string|null, endedAt: string|null}, stalenessSeconds: 43200,
 *     budget: array{pointTarget: 20000, byteTarget: 3145728, exceeded: bool},
 *     scope: 'versions', distributionId: int, series: list<HistorySeries>
 * }
 *
 * @phpstan-import-type ObservationGroup from HistoryObservations
 */
final class ReadDownloadHistory
{
    public function __construct(
        private readonly HistoryObservations $observations = new HistoryObservations,
        private readonly HistoryTimeline $timeline = new HistoryTimeline,
    ) {}

    /** @return AggregateHistory|DistributionHistory */
    public function __invoke(string $range = '30d', ?int $distributionId = null, ?CarbonImmutable $anchorAt = null): array
    {
        if (! in_array($range, ['7d', '30d', '90d', 'all'], true)) {
            throw new InvalidArgumentException('Unsupported history range.');
        }

        $distributions = DB::table('distributions')->where('active', true)
            ->orderByRaw("CASE WHEN provider = 'modrinth' AND loader = 'combined' THEN 10 WHEN provider = 'curseforge' AND loader = 'fabric' THEN 20 WHEN provider = 'curseforge' AND loader = 'paper' THEN 30 ELSE 100 END")
            ->orderBy('id')->get(['id', 'name']);
        $activeIds = array_values($distributions->map(fn (object $row): int => (int) $row->id)->all());

        if ($distributionId !== null && ! in_array($distributionId, $activeIds, true)) {
            throw (new ModelNotFoundException)->setModel(Distribution::class, [$distributionId]);
        }

        if ($anchorAt === null) {
            $anchorAt = LatestCollection::current()->capturedAt;
        } elseif (! DB::table('metric_snapshots')->whereIn('distribution_id', $activeIds)
            ->where('captured_at', $anchorAt->utc()->format($anchorAt->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u'))->exists()) {
            throw new InvalidArgumentException('History anchor must be a stored active aggregate capture.');
        }

        $identities = $distributionId === null
            ? $distributions->map(fn (object $row): array => ['id' => (int) $row->id, 'distributionId' => (int) $row->id, 'label' => (string) $row->name])->all()
            : DB::table('distribution_versions')->where('distribution_id', $distributionId)->whereNull('excluded_at')
                ->orderByRaw('CASE WHEN published_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('published_at')->orderByDesc('id')
                ->get(['id', 'distribution_id', 'display_name'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'distributionId' => (int) $row->distribution_id, 'label' => (string) $row->display_name])->all();

        $end = $anchorAt === null ? null : $anchorAt->getTimestamp() * 1000;
        $start = $end === null ? null : ($range === 'all' ? $this->earliest($activeIds, $end) : $end - (int) $range * 86400000);
        $groups = $start === null ? [] : $this->observations->read(array_column($identities, 'id'), $start, $end, $distributionId !== null);
        $series = [];
        $streams = [];
        $fullPoints = [];

        foreach ($identities as $identity) {
            $group = $groups[$identity['id']] ?? ['rows' => [], 'count' => 0, 'reduced' => false, 'bucketSeconds' => null];
            $points = $start === null ? [] : $this->timeline->points($group['rows'], $start, $end);
            $streams[$identity['id']] = $group['rows'];
            // Aggregate streams remain exact until the total has been aligned.
            if ($distributionId === null) {
                array_push($fullPoints, ...$points);
            }
            $series[] = [
                'key' => ($distributionId === null ? 'distribution:' : 'version:').$identity['id'],
                ...$identity,
                'historyStatus' => $group['count'] >= 2 ? 'ready' : ($group['count'] === 1 ? 'single_point' : ($group['rows'] === [] ? 'never_collected' : 'outside_range')),
                'observationCount' => $group['count'],
                'points' => $points,
                'sampling' => $this->seriesSampling($group, $points),
            ];
        }

        $response = [
            'range' => $range, 'anchorAt' => $anchorAt?->utc()->toJSON(),
            'domain' => ['startedAt' => $start === null ? null : HistoryTimeline::timestamp($start), 'endedAt' => $end === null ? null : HistoryTimeline::timestamp($end)],
            'stalenessSeconds' => 43200,
            'budget' => ['pointTarget' => 20000, 'byteTarget' => 3145728, 'exceeded' => false],
            'series' => $series,
        ];

        if ($distributionId !== null) {
            $response['scope'] = 'versions';
            $response['distributionId'] = $distributionId;
        } else {
            $response['scope'] = 'aggregate';
            $aligned = $start === null ? ['points' => [], 'observationCount' => 0] : $this->timeline->totals($streams, $fullPoints, $start, $end);
            $total = ['key' => 'total', 'points' => $aligned['points'], 'sampling' => $this->timeline->sampling(false, null, $aligned['observationCount'], $aligned['observationCount'], count($aligned['points']))];
            if ($start !== null) {
                $this->reduceAggregate($series, $total, $start, $end);
            }
            $response['series'] = $series;
            $response['total'] = $total;
        }

        $pointCount = array_sum(array_column(array_column($response['series'], 'sampling'), 'returnedPointCount')) + ($response['total']['sampling']['returnedPointCount'] ?? 0);
        $response['budget']['exceeded'] = $pointCount > 20000 || strlen(json_encode($response, JSON_THROW_ON_ERROR)) > 3145728;

        return $response;
    }

    /** @param list<int> $activeIds */
    private function earliest(array $activeIds, int $end): int
    {
        $cutoff = HistoryTimeline::timestamp($end, database: true);
        $aggregate = DB::table('metric_snapshots')->whereIn('distribution_id', $activeIds)->where('captured_at', '<=', $cutoff)->min('captured_at');
        $detail = DB::table('distribution_version_snapshots as s')->join('distribution_versions as v', 'v.id', '=', 's.distribution_version_id')
            ->whereIn('v.distribution_id', $activeIds)->whereNull('v.excluded_at')->where('s.captured_at', '<=', $cutoff)->min('s.captured_at');

        $times = [$end]; // The validated aggregate anchor is always in the domain.
        foreach ([$aggregate, $detail] as $time) {
            if (is_string($time)) {
                $times[] = CarbonImmutable::parse($time, 'UTC')->getTimestamp() * 1000;
            }
        }

        return min($times);
    }

    /**
     * @param  ObservationGroup  $group
     * @param  list<HistoryPoint>  $points
     * @return HistorySampling
     */
    private function seriesSampling(array $group, array $points): array
    {
        $observed = count(array_filter($points, fn (array $point): bool => $point['kind'] === 'observed'));

        return $this->timeline->sampling($group['reduced'], $group['bucketSeconds'], $group['count'], $observed, count($points));
    }

    /**
     * @param  list<HistorySeries>  $series
     * @param  HistoryTotal  $total
     */
    private function reduceAggregate(array &$series, array &$total, int $start, int $end): void
    {
        $scopeCount = count($total['points']) + array_sum(array_map(fn (array $row): int => count($row['points']), $series));
        $buckets = min(120, max(1, (int) floor(20000 / (4 * max(1, count($series) + 1)))));
        $width = max(1, (int) ceil(($end - $start) / 1000 / $buckets));

        foreach ($series as &$row) {
            if (count($row['points']) > 1500 || $scopeCount > 20000) {
                $row['points'] = $this->timeline->reduce($row['points'], $start, $end, $width);
                $observed = count(array_filter($row['points'], fn (array $point): bool => $point['kind'] === 'observed'));
                $row['sampling'] = $this->timeline->sampling(true, $width, $row['observationCount'], $observed, count($row['points']));
            }
        }

        unset($row);
        if (count($total['points']) > 1500 || $scopeCount > 20000) {
            $total['points'] = $this->timeline->reduce($total['points'], $start, $end, $width);
            $observed = count(array_filter($total['points'], fn (array $point): bool => in_array('observed', array_column($point['contributors'], 'status'), true)));
            $total['sampling'] = $this->timeline->sampling(true, $width, $total['sampling']['originalObservationCount'], $observed, count($total['points']));
        }
    }
}
