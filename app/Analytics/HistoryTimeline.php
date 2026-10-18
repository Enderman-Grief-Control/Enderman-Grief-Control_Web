<?php

namespace App\Analytics;

/**
 * UTC millisecond math keeps synthetic expiry endpoints distinct from captures.
 *
 * @phpstan-import-type Observation from HistoryObservations
 * @phpstan-import-type HistoryPoint from ReadDownloadHistory
 * @phpstan-import-type HistoryTotalPoint from ReadDownloadHistory
 * @phpstan-import-type HistorySampling from ReadDownloadHistory
 */
final class HistoryTimeline
{
    public const FRESHNESS_MS = 43200000;

    public static function timestamp(int $milliseconds, bool $database = false): string
    {
        $seconds = (int) floor($milliseconds / 1000);
        $fraction = ($milliseconds - $seconds * 1000) * 1000;

        if ($database) {
            return gmdate('Y-m-d H:i:s', $seconds).($fraction === 0 ? '' : sprintf('.%06d', $fraction));
        }

        return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%06dZ', $fraction);
    }

    /**
     * @param  list<Observation>  $rows
     * @return list<HistoryPoint>
     */
    public function points(array $rows, int $start, int $end): array
    {
        $points = [];
        foreach ($rows as $row) {
            $time = $row['time'];
            $expires = $time + self::FRESHNESS_MS;
            $at = max($time, $start);
            $points[$at] = $this->point($at, $row, $at >= $expires ? 'gap' : ($at === $time ? 'observed' : 'carried'));

            if ($expires >= $start && $expires <= $end && ($row['nextTime'] === null || $row['nextTime'] > $expires)) {
                if ($expires - 1 >= $start) {
                    $points[$expires - 1] = $this->point($expires - 1, $row, 'carried');
                }
                $points[$expires] = $this->point($expires, $row, 'gap');
            }
            if ($row['nextTime'] === null && $time < $end && $end < $expires) {
                $points[$end] = $this->point($end, $row, 'carried');
            }
        }
        ksort($points, SORT_NUMERIC);

        return array_values($points);
    }

    /**
     * @param  Observation  $source
     * @param  'observed'|'carried'|'gap'  $kind
     * @return HistoryPoint
     */
    private function point(int $at, array $source, string $kind): array
    {
        return [
            'at' => self::timestamp($at),
            'downloads' => $kind === 'gap' ? null : $source['downloads'],
            'sourceCapturedAt' => self::timestamp($source['time']),
            'kind' => $kind,
            'gapReason' => $kind === 'gap' ? 'expired' : null,
        ];
    }

    /**
     * Calculate totals from the FULL stream, before reducing any component.
     *
     * @param  array<int, list<Observation>>  $streams
     * @param  list<HistoryPoint>  $seriesPoints
     * @return array{points: list<HistoryTotalPoint>, observationCount: int}
     */
    public function totals(array $streams, array $seriesPoints, int $start, int $end): array
    {
        if ($streams === []) {
            return ['points' => [], 'observationCount' => 0];
        }
        $times = [$start => true, $end => true];
        $observationTimes = [];
        foreach ($seriesPoints as $point) {
            $times[$this->milliseconds($point['at'])] = true;
        }
        foreach ($streams as $rows) {
            foreach ($rows as $row) {
                if ($row['time'] >= $start) {
                    $observationTimes[$row['time']] = true;
                }
            }
        }
        ksort($times, SORT_NUMERIC);
        $indexes = array_fill_keys(array_keys($streams), -1);
        $points = [];
        foreach ($times as $time => $_) {
            $contributors = [];
            $known = 0;
            $usable = 0;
            foreach ($streams as $id => $rows) {
                while (isset($rows[$indexes[$id] + 1]) && $rows[$indexes[$id] + 1]['time'] <= $time) {
                    $indexes[$id]++;
                }
                $source = $rows[$indexes[$id]] ?? null;
                $fresh = $source !== null && $time - $source['time'] < self::FRESHNESS_MS;
                if ($fresh) {
                    $known += $source['downloads'];
                    $usable++;
                }
                $contributors[] = [
                    'distributionId' => $id,
                    'downloads' => $fresh ? $source['downloads'] : null,
                    'sourceCapturedAt' => $source === null ? null : self::timestamp($source['time']),
                    'status' => $source === null ? 'unknown' : (! $fresh ? 'stale' : ($source['time'] === $time ? 'observed' : 'carried')),
                ];
            }
            $points[] = [
                'at' => self::timestamp($time),
                'downloads' => $usable === count($streams) ? $known : null,
                'knownDownloads' => $usable === 0 ? null : $known,
                'status' => $usable === 0 ? 'unavailable' : ($usable === count($streams) ? 'complete' : 'partial'),
                'contributors' => $contributors,
            ];
        }

        return ['points' => $points, 'observationCount' => count($observationTimes)];
    }

    /**
     * Keep synthetic boundaries, coverage transitions and both correction
     * neighbors in addition to each bucket's first/min/max/last real values.
     *
     * @template T of HistoryPoint|HistoryTotalPoint
     *
     * @param  list<T>  $points
     * @return list<T>
     */
    public function reduce(array $points, int $start, int $end, int $width): array
    {
        if ($points === []) {
            return [];
        }
        $keep = [0 => true, count($points) - 1 => true];
        $buckets = [];
        $lastObservation = null;
        $lastBucket = max(0, (int) ceil(($end - $start) / ($width * 1000)) - 1);
        foreach ($points as $i => $point) {
            if (isset($point['kind']) && $point['kind'] !== 'observed') {
                $keep[$i] = true;
            }
            if ($i > 0) {
                $previous = $points[$i - 1];
                $coverageChanged = ($previous['status'] ?? null) !== ($point['status'] ?? null);
                // Contributor changes within a partial total matter too.
                if (isset($point['contributors'], $previous['contributors'])) {
                    foreach ($point['contributors'] as $j => $contributor) {
                        if (($contributor['downloads'] === null) !== ($previous['contributors'][$j]['downloads'] === null)) {
                            $coverageChanged = true;
                        }
                    }
                }
                $value = $point['downloads'] ?? ($point['knownDownloads'] ?? null);
                $previousValue = $previous['downloads'] ?? ($previous['knownDownloads'] ?? null);
                if ($coverageChanged || (($point['downloads'] === null) !== ($previous['downloads'] === null))
                    || ($value !== null && $previousValue !== null && $value < $previousValue)) {
                    $keep[$i - 1] = $keep[$i] = true;
                }
            }
            $time = $this->milliseconds($point['at']);
            if ($time === $start || $time === $end) {
                $keep[$i] = true;
            }
            $observed = ($point['kind'] ?? null) === 'observed'
                || (isset($point['contributors']) && in_array('observed', array_column($point['contributors'], 'status'), true));
            if (! $observed) {
                continue;
            }
            // A real correction still has two real neighbors when a freshness
            // gap or synthetic endpoint lies between their captures.
            if (isset($point['kind']) && $lastObservation !== null && $point['downloads'] < $points[$lastObservation]['downloads']) {
                $keep[$lastObservation] = $keep[$i] = true;
            }
            $lastObservation = $i;
            $bucket = min($lastBucket, (int) floor(($time - $start) / ($width * 1000)));
            $value = $point['downloads'] ?? ($point['knownDownloads'] ?? null);
            if (! isset($buckets[$bucket])) {
                $buckets[$bucket] = ['first' => $i, 'last' => $i, 'min' => null, 'max' => null];
            }
            // Partial totals are still observations. Preserve bucket endpoints
            // and use the labelled known subtotal for their extrema only.
            $buckets[$bucket]['last'] = $i;
            if ($value !== null) {
                foreach (['min', 'max'] as $extreme) {
                    $oldIndex = $buckets[$bucket][$extreme];
                    $old = $oldIndex === null ? null : ($points[$oldIndex]['downloads'] ?? ($points[$oldIndex]['knownDownloads'] ?? null));
                    if ($old === null || ($extreme === 'min' && $value < $old) || ($extreme === 'max' && $value > $old)) {
                        $buckets[$bucket][$extreme] = $i;
                    }
                }
            }
        }
        foreach ($buckets as $bucket) {
            foreach ($bucket as $index) {
                if ($index !== null) {
                    $keep[$index] = true;
                }
            }
        }
        ksort($keep, SORT_NUMERIC);

        return array_values(array_intersect_key($points, $keep));
    }

    public function milliseconds(string $iso): int
    {
        // Every input here was serialized by timestamp(), with millisecond precision.
        return (int) strtotime($iso) * 1000 + (int) substr($iso, 20, 3);
    }

    /** @return HistorySampling */
    public function sampling(bool $reduced, ?int $width, int $original, int $observations, int $points): array
    {
        return [
            'mode' => $reduced ? 'min_max' : 'exact', 'bucketSeconds' => $reduced ? $width : null,
            'originalObservationCount' => $original, 'returnedObservationCount' => $observations,
            'returnedPointCount' => $points,
        ];
    }
}
