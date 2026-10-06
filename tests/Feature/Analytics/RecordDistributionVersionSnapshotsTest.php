<?php

namespace Tests\Feature\Analytics;

use App\Analytics\DistributionVersionMetrics;
use App\Analytics\RecordDistributionVersionSnapshots;
use App\Models\Distribution;
use App\Models\DistributionVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordDistributionVersionSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_metrics_are_upserted_and_snapshotted(): void
    {
        $distribution = $this->createDistribution();
        $publishedAt = CarbonImmutable::parse('2026-09-19 12:30:00', 'UTC');
        $firstCapturedAt = CarbonImmutable::parse('2026-09-20 01:00:00', 'UTC');
        $secondCapturedAt = CarbonImmutable::parse('2026-09-20 07:00:00', 'UTC');
        $recordDistributionVersionSnapshots = new RecordDistributionVersionSnapshots;

        $firstRecorded = $recordDistributionVersionSnapshots($distribution, [
            new DistributionVersionMetrics(
                providerVersionIdentifier: 'modrinth-version-123',
                versionNumber: 'paper-1.21.8-1.0.0',
                displayName: 'Enderman Grief Control Paper 1.21.8',
                loaders: ['paper'],
                gameVersions: ['1.21.8'],
                downloads: 100,
                publishedAt: $publishedAt,
                capturedAt: $firstCapturedAt,
                metadata: ['version_type' => 'release'],
            ),
        ]);

        $secondRecorded = $recordDistributionVersionSnapshots($distribution, [
            new DistributionVersionMetrics(
                providerVersionIdentifier: 'modrinth-version-123',
                versionNumber: 'paper-1.21.8-1.0.1',
                displayName: 'Enderman Grief Control Paper 1.21.8 Patch',
                loaders: ['paper'],
                gameVersions: ['1.21.8'],
                downloads: 125,
                publishedAt: $publishedAt,
                capturedAt: $secondCapturedAt,
                metadata: ['version_type' => 'release'],
            ),
        ]);

        $this->assertSame(1, $firstRecorded);
        $this->assertSame(1, $secondRecorded);
        $this->assertDatabaseCount('distribution_versions', 1);
        $this->assertDatabaseCount('distribution_version_snapshots', 2);

        $version = DistributionVersion::query()->firstOrFail();

        $this->assertSame($distribution->id, $version->distribution_id);
        $this->assertSame('modrinth-version-123', $version->provider_version_identifier);
        $this->assertSame('paper-1.21.8-1.0.1', $version->version_number);
        $this->assertSame('Enderman Grief Control Paper 1.21.8 Patch', $version->display_name);
        $this->assertSame(['paper'], $version->loaders);
        $this->assertSame(['1.21.8'], $version->game_versions);
        $this->assertSame(['version_type' => 'release'], $version->metadata);
        $this->assertSame([100, 125], $version->snapshots()->orderBy('id')->pluck('downloads')->all());
    }

    public function test_zero_version_metrics_are_a_successful_no_op(): void
    {
        $recorded = (new RecordDistributionVersionSnapshots)($this->createDistribution(), []);

        $this->assertSame(0, $recorded);
        $this->assertDatabaseCount('distribution_versions', 0);
        $this->assertDatabaseCount('distribution_version_snapshots', 0);
    }

    private function createDistribution(): Distribution
    {
        return Distribution::query()->create([
            'provider' => 'modrinth',
            'name' => 'Modrinth',
            'loader' => 'combined',
            'project_identifier' => '6jCDxmNc',
            'listing_url' => 'https://modrinth.com/plugin/enderman-grief-control',
            'active' => true,
        ]);
    }
}
