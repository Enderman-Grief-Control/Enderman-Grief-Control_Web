<?php

namespace App\Analytics;

use App\Models\Distribution;
use App\Models\MetricSnapshot;

final class RecordMetricSnapshot
{
    /**
     * Store one historical metric capture for a distribution.
     *
     * Duplicate collection attempts intentionally append snapshots. The
     * collector command can decide later whether to skip identical captures.
     */
    public function __invoke(Distribution $distribution, ProjectMetrics $metrics): MetricSnapshot
    {
        return $distribution->metricSnapshots()->create([
            'downloads' => $metrics->downloads,
            'followers' => $metrics->followers,
            'likes' => $metrics->likes,
            'captured_at' => $metrics->capturedAt,
        ]);
    }
}
