<?php

namespace App\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bounded scalar reads. Windows see all deduplicated observations before the
 * final filter; sampling must never turn an omitted capture into a false gap.
 *
 * @phpstan-type Observation array{time: int, downloads: int, nextTime: int|null}
 * @phpstan-type ObservationGroup array{rows: list<Observation>, count: int, reduced: bool, bucketSeconds: int|null}
 */
final class HistoryObservations
{
    /**
     * @param  list<int>  $ids
     * @return array<int, ObservationGroup>
     */
    public function read(array $ids, int $start, int $end, bool $versions): array
    {
        if ($ids === []) {
            return [];
        }

        $scoped = $this->scopedSnapshots($ids, $start, $end, $versions);
        $neighbors = $this->withNeighbors($scoped, intdiv($start, 1000));
        $width = null;

        if ($versions) {
            $buckets = min(120, max(1, (int) floor(20000 / (4 * count($ids)))));
            $width = max(1, (int) ceil(($end - $start) / 1000 / $buckets));
            $budgeted = $this->withSamplingBudget(
                $neighbors,
                intdiv($start, 1000),
                intdiv($end, 1000),
                $buckets,
                $width
            );
            $query = $this->retainedSamples($budgeted, intdiv($start, 1000));
        } else {
            // Totals must align the completea stream before PHP reduces it.
            $query = DB::query()->fromSub($neighbors, 'neighbors')->selectRaw('*, 0 AS reduced');
        }

        $groups = [];
        foreach ($query->orderBy('series')->orderBy('time')->get() as $row) {
            $id = (int) $row->series;
            $reduced = $versions && (bool) $row->reduced;
            $groups[$id] ??= [
                'rows' => [],
                'count' => (int) $row->observation_count,
                'reduced' => $reduced,
                'bucketSeconds' => $reduced ? $width : null,
            ];
            $groups[$id]['rows'][] = [
                'time' => (int) $row->time * 1000,
                'downloads' => (int) $row->downloads,
                'nextTime' => $row->next_time === null
                    ? null
                    : (int) $row->next_time * 1000,
            ];
        }

        return $groups;
    }

    /**
     * In-range captures plus one newest pre-range source per identity.
     * Table and column choices are internal constants; values stay bound.
     *
     * @param  list<int>  $ids
     */
    private function scopedSnapshots(array $ids, int $start, int $end, bool $versions): Builder
    {
        $table = $versions ? 'distribution_version_snapshots' : 'metric_snapshots';
        $owners = $versions ? 'distribution_versions' : 'distributions';
        $foreign = $versions ? 'distribution_version_id' : 'distribution_id';
        $startedAt = HistoryTimeline::timestamp($start, database: true);
        $endedAt = HistoryTimeline::timestamp($end, database: true);

        $boundaryIds = DB::table($owners.' as o')->whereIn('o.id', $ids)
            ->selectSub(
                DB::table($table.' as x')
                    ->select('x.id')
                    ->whereColumn('x.'.$foreign, 'o.id')
                    ->where('x.captured_at', '<', $startedAt)
                    ->orderByDesc('x.captured_at')->orderByDesc('x.id')
                    ->limit(1),
                'snapshot_id',
            );
        $boundaries = DB::query()->fromSub($boundaryIds, 'boundary')
            ->join($table.' as s', 's.id', '=', 'boundary.snapshot_id')
            ->select(['s.id', 's.'.$foreign.' as series', 's.downloads', 's.captured_at']);

        return DB::table($table)->select(['id', $foreign.' as series', 'downloads', 'captured_at'])
            ->whereIn($foreign, $ids)
            ->whereBetween('captured_at', [$startedAt, $endedAt])
            ->unionAll($boundaries);
    }

    /** Resolve timestamp ties, then annotate the full stream before sampling. */
    private function withNeighbors(Builder $scoped, int $start): Builder
    {
        $tied = DB::query()->fromSub($scoped, 'scoped')->selectRaw(
            '*, ROW_NUMBER() OVER (PARTITION BY series, captured_at ORDER BY id DESC) AS tie',
        );
        $timed = DB::query()->fromSub($tied, 'tied')->where('tie', 1)
            ->select(['id', 'series', 'downloads'])->selectRaw($this->captureEpoch().' AS time');

        return DB::query()->fromSub($timed, 'observations')->selectRaw(<<<'SQL'
            *, LAG(downloads) OVER (PARTITION BY series ORDER BY time, id) AS previous_downloads,
            LEAD(downloads) OVER (PARTITION BY series ORDER BY time, id) AS next_downloads,
            LAG(time) OVER (PARTITION BY series ORDER BY time, id) AS previous_time,
            LEAD(time) OVER (PARTITION BY series ORDER BY time, id) AS next_time,
            SUM(CASE WHEN time >= ? THEN 1 ELSE 0 END) OVER (PARTITION BY series) AS observation_count
            SQL, [$start]);
    }

    /** Count real and synthetic points on the full stream to choose exact/reduced mode. */
    private function withSamplingBudget(Builder $neighbors, int $start, int $end, int $buckets, int $width): Builder
    {
        $weighted = DB::query()->fromSub($neighbors, 'neighbors')->select('neighbors.*')
            // A boundary source coincident with a real capture at S adds no point.
            ->selectRaw('CASE WHEN time < ? AND next_time = ? THEN 0 ELSE 1 END AS capture_points', [$start, $start])
            // Expiry adds a gap and an endpoint 1ms earlier, within the domain.
            ->selectRaw(<<<'SQL'
                CASE WHEN time + 43200 BETWEEN ? AND ? AND (next_time IS NULL OR next_time > time + 43200)
                    THEN CASE WHEN time + 43200 > ? THEN 1 ELSE 0 END
                        + CASE WHEN time + 43200 - 0.001 >= ? THEN 1 ELSE 0 END
                    ELSE 0 END AS expiry_points
                SQL, [$start, $end, $start, $start])
            ->selectRaw('CASE WHEN next_time IS NULL AND time < ? AND time + 43200 > ? THEN 1 ELSE 0 END AS end_points', [$end, $end])
            ->selectRaw('CASE WHEN time < ? THEN 0 WHEN time >= ? THEN ? ELSE CAST((time - ?) / ? AS INTEGER) END AS bucket', [$start, $end, $buckets - 1, $start, $width]);

        return DB::query()->fromSub($weighted, 'weighted')->selectRaw(<<<'SQL'
            *, SUM(capture_points + expiry_points + end_points) OVER (PARTITION BY series) AS point_count,
            SUM(capture_points + expiry_points + end_points) OVER () AS scope_points
            SQL);
    }

    /** Keep bucket extrema/endpoints and protected full-history correction/gap neighbors. */
    private function retainedSamples(Builder $budgeted, int $start): Builder
    {
        $ranked = DB::query()->fromSub($budgeted, 'budgeted')->selectRaw(<<<'SQL'
            *, ROW_NUMBER() OVER (PARTITION BY series, bucket ORDER BY time, id) AS first_rank,
            ROW_NUMBER() OVER (PARTITION BY series, bucket ORDER BY time DESC, id DESC) AS last_rank,
            ROW_NUMBER() OVER (PARTITION BY series, bucket ORDER BY downloads, time, id) AS min_rank,
            ROW_NUMBER() OVER (PARTITION BY series, bucket ORDER BY downloads DESC, time, id) AS max_rank
            SQL);

        return DB::query()->fromSub($ranked, 'ranked')
            ->select(['series', 'downloads', 'time', 'next_time', 'observation_count'])
            ->selectRaw('CASE WHEN point_count > 1500 OR scope_points > 20000 THEN 1 ELSE 0 END AS reduced')
            ->whereRaw(<<<'SQL'
                (point_count <= 1500 AND scope_points <= 20000)
                OR first_rank = 1 OR last_rank = 1 OR min_rank = 1 OR max_rank = 1
                OR previous_time IS NULL OR next_time IS NULL OR time < ?
                OR downloads < previous_downloads OR next_downloads < downloads
                OR time - previous_time > 43200 OR next_time - time > 43200
                SQL, [$start]);
    }

    /** @return literal-string */
    private function captureEpoch(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'unixepoch(captured_at)',
            'pgsql' => 'CAST(EXTRACT(EPOCH FROM captured_at) AS BIGINT)',
            default => throw new \LogicException('Download history supports SQLite and PostgreSQL.'),
        };
    }
}
