<?php

namespace App\Analytics;

use App\Models\Distribution;
use App\Models\DistributionVersion;

final class RecordDistributionVersionSnapshots
{
    /**
     * Store one detailed metric capture for each provider version/file.
     *
     * A version/file the maintainer removed from the dashboard becomes
     * available again when it reappears in collection.
     *
     * @param  list<DistributionVersionMetrics>  $versionMetrics
     */
    public function __invoke(Distribution $distribution, array $versionMetrics): int
    {
        $recordedSnapshots = 0;

        foreach ($versionMetrics as $metrics) {
            $version = DistributionVersion::query()->updateOrCreate(
                [
                    'distribution_id' => $distribution->id,
                    'provider_version_identifier' => $metrics->providerVersionIdentifier,
                ],
                [
                    'version_number' => $metrics->versionNumber,
                    'display_name' => $metrics->displayName,
                    'loaders' => $metrics->loaders,
                    'game_versions' => $metrics->gameVersions,
                    'published_at' => $metrics->publishedAt,
                    'metadata' => $metrics->metadata,
                    'excluded_at' => null,
                ],
            );

            $version->snapshots()->create([
                'downloads' => $metrics->downloads,
                'captured_at' => $metrics->capturedAt,
            ]);

            $recordedSnapshots++;
        }

        return $recordedSnapshots;
    }
}
