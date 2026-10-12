<?php

namespace Tests\Feature\Analytics;

use App\Analytics\ReadDashboardAnalytics;
use App\Models\Distribution;
use App\Models\DistributionVersion;
use App\Models\DistributionVersionSnapshot;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadDashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_launch_distributions_when_snapshots_are_missing(): void
    {
        $modrinth = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $fabric = $this->createDistribution('curseforge', 'CurseForge Fabric', 'fabric');
        $paper = $this->createDistribution('curseforge', 'CurseForge Paper', 'paper');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame(0, $analytics['totalDownloads']);
        $this->assertNull($analytics['lastUpdatedAt']);
        $this->assertFalse($analytics['hasSnapshots']);
        $this->assertTrue($analytics['hasMissingSnapshots']);
        $this->assertSame(
            [$modrinth->id, $fabric->id, $paper->id],
            array_column($analytics['distributions'], 'id'),
        );
        $this->assertSame(['missing', 'missing', 'missing'], array_column($analytics['distributions'], 'status'));
        $this->assertSame(['day', 'week', 'month'], array_column($analytics['growth']['ranges'], 'key'));
        $this->assertSame(
            ['insufficient_history', 'insufficient_history', 'insufficient_history'],
            array_column($analytics['growth']['ranges'], 'status'),
        );
    }

    public function test_it_uses_each_distributions_latest_snapshot_for_totals_and_timestamps(): void
    {
        $modrinth = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $fabric = $this->createDistribution('curseforge', 'CurseForge Fabric', 'fabric');
        $paper = $this->createDistribution('curseforge', 'CurseForge Paper', 'paper');

        $this->createSnapshot($modrinth, 100, '2026-09-19 09:00:00');
        $this->createSnapshot($modrinth, 150, '2026-09-20 09:00:00', followers: 7);
        $this->createSnapshot($fabric, 30, '2026-09-20 08:00:00', likes: 3);
        $this->createSnapshot($paper, 20, '2026-09-18 08:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame(200, $analytics['totalDownloads']);
        $this->assertSame('2026-09-20T09:00:00.000000Z', $analytics['lastUpdatedAt']);
        $this->assertTrue($analytics['hasSnapshots']);
        $this->assertFalse($analytics['hasMissingSnapshots']);
        $this->assertSame([150, 30, 20], array_column($analytics['distributions'], 'downloads'));
        $this->assertSame(7, $analytics['distributions'][0]['followers']);
        $this->assertSame(3, $analytics['distributions'][1]['likes']);
    }

    public function test_it_calculates_aggregate_growth_ranges_from_stored_snapshots(): void
    {
        $modrinth = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $fabric = $this->createDistribution('curseforge', 'CurseForge Fabric', 'fabric');

        $this->createSnapshot($modrinth, 400, '2026-09-06 12:00:00');
        $this->createSnapshot($modrinth, 700, '2026-09-29 12:00:00');
        $this->createSnapshot($modrinth, 900, '2026-10-05 12:00:00');
        $this->createSnapshot($modrinth, 1000, '2026-10-06 12:00:00');
        $this->createSnapshot($fabric, 100, '2026-09-06 11:30:00');
        $this->createSnapshot($fabric, 300, '2026-09-29 11:30:00');
        $this->createSnapshot($fabric, 430, '2026-10-05 11:30:00');
        $this->createSnapshot($fabric, 500, '2026-10-06 06:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame([170, 500, 1000], array_column($analytics['growth']['ranges'], 'downloads'));
        $this->assertSame(['ready', 'ready', 'ready'], array_column($analytics['growth']['ranges'], 'status'));
        $this->assertSame('2026-10-06T12:00:00.000000Z', $analytics['growth']['ranges'][0]['capturedAt']);
        $this->assertSame('2026-10-05T12:00:00.000000Z', $analytics['growth']['ranges'][0]['rangeStartedAt']);
        $this->assertSame([], $analytics['growth']['ranges'][0]['missingDistributionIds']);
        $this->assertSame([100, 300, 600], array_column($analytics['distributions'][0]['growth'], 'downloads'));
        $this->assertSame([70, 200, 400], array_column($analytics['distributions'][1]['growth'], 'downloads'));
        $this->assertSame('2026-10-05T11:30:00.000000Z', $analytics['distributions'][1]['growth'][0]['baselineAt']);
    }

    public function test_it_selects_the_newest_baseline_at_or_before_the_range_boundary(): void
    {
        $distribution = $this->createDistribution('modrinth', 'Modrinth', 'combined');

        $this->createSnapshot($distribution, 50, '2026-10-05 11:00:00');
        $this->createSnapshot($distribution, 80, '2026-10-05 12:00:00');
        $this->createSnapshot($distribution, 85, '2026-10-05 12:00:00');
        $this->createSnapshot($distribution, 95, '2026-10-05 13:00:00');
        $this->createSnapshot($distribution, 100, '2026-10-06 12:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame(15, $analytics['distributions'][0]['growth'][0]['downloads']);
        $this->assertSame('2026-10-05T12:00:00.000000Z', $analytics['distributions'][0]['growth'][0]['baselineAt']);
    }

    public function test_it_marks_total_growth_partial_when_some_active_distributions_lack_history(): void
    {
        $modrinth = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $fabric = $this->createDistribution('curseforge', 'CurseForge Fabric', 'fabric');
        $inactive = $this->createDistribution('curseforge', 'Inactive CurseForge', 'paper', active: false);

        $this->createSnapshot($modrinth, 100, '2026-10-05 12:00:00');
        $this->createSnapshot($modrinth, 150, '2026-10-06 12:00:00');
        $this->createSnapshot($fabric, 20, '2026-10-06 12:00:00');
        $this->createSnapshot($inactive, 1000, '2026-10-05 12:00:00');
        $this->createSnapshot($inactive, 2000, '2026-10-06 12:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame(50, $analytics['growth']['ranges'][0]['downloads']);
        $this->assertSame('partial', $analytics['growth']['ranges'][0]['status']);
        $this->assertSame([$fabric->id], $analytics['growth']['ranges'][0]['missingDistributionIds']);
        $this->assertSame([$modrinth->id, $fabric->id], array_column($analytics['distributions'], 'id'));
    }

    public function test_it_marks_growth_insufficient_when_no_distribution_has_a_baseline(): void
    {
        $modrinth = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $fabric = $this->createDistribution('curseforge', 'CurseForge Fabric', 'fabric');

        $this->createSnapshot($modrinth, 150, '2026-10-06 12:00:00');
        $this->createSnapshot($fabric, 20, '2026-10-06 12:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertNull($analytics['growth']['ranges'][0]['downloads']);
        $this->assertSame('insufficient_history', $analytics['growth']['ranges'][0]['status']);
        $this->assertSame([$modrinth->id, $fabric->id], $analytics['growth']['ranges'][0]['missingDistributionIds']);
    }

    public function test_it_preserves_negative_deltas_as_provider_counter_corrections(): void
    {
        $distribution = $this->createDistribution('modrinth', 'Modrinth', 'combined');

        $this->createSnapshot($distribution, 120, '2026-10-05 12:00:00');
        $this->createSnapshot($distribution, 100, '2026-10-06 12:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame(-20, $analytics['distributions'][0]['growth'][0]['downloads']);
        $this->assertSame(-20, $analytics['growth']['ranges'][0]['downloads']);
        $this->assertSame('ready', $analytics['growth']['ranges'][0]['status']);
    }

    public function test_it_breaks_latest_snapshot_ties_by_newest_id(): void
    {
        $distribution = $this->createDistribution('modrinth', 'Modrinth', 'combined');

        $this->createSnapshot($distribution, 100, '2026-09-20 09:00:00');
        $this->createSnapshot($distribution, 175, '2026-09-20 09:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame(175, $analytics['totalDownloads']);
        $this->assertSame(175, $analytics['distributions'][0]['downloads']);
    }

    public function test_it_includes_active_distributions_and_ignores_inactive_distributions(): void
    {
        $active = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $inactive = $this->createDistribution('curseforge', 'Inactive CurseForge', 'fabric', active: false);
        $other = $this->createDistribution('other', 'Other', 'combined');

        $this->createSnapshot($active, 100, '2026-09-20 09:00:00');
        $this->createSnapshot($inactive, 500, '2026-09-20 09:00:00');
        $this->createSnapshot($other, 900, '2026-09-20 09:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame(1000, $analytics['totalDownloads']);
        $this->assertSame([$active->id, $other->id], array_column($analytics['distributions'], 'id'));
    }

    public function test_it_marks_partial_snapshot_data_as_missing(): void
    {
        $modrinth = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $fabric = $this->createDistribution('curseforge', 'CurseForge Fabric', 'fabric');

        $this->createSnapshot($modrinth, 100, '2026-09-20 09:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame(100, $analytics['totalDownloads']);
        $this->assertTrue($analytics['hasSnapshots']);
        $this->assertTrue($analytics['hasMissingSnapshots']);
        $this->assertSame('current', $analytics['distributions'][0]['status']);
        $this->assertSame($fabric->id, $analytics['distributions'][1]['id']);
        $this->assertSame('missing', $analytics['distributions'][1]['status']);
        $this->assertNull($analytics['distributions'][1]['capturedAt']);
    }

    public function test_it_reports_distribution_state_and_coverage_for_the_latest_collection_window(): void
    {
        $modrinth = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $fabric = $this->createDistribution('curseforge', 'CurseForge Fabric', 'fabric');
        $paper = $this->createDistribution('curseforge', 'CurseForge Paper', 'paper');
        $other = $this->createDistribution('other', 'Other', 'combined');
        $inactive = $this->createDistribution('other', 'Inactive', 'paper', active: false);

        $this->createSnapshot($modrinth, 100, '2026-10-06 12:00:00');
        $this->createSnapshot($fabric, 50, '2026-10-06 11:30:00');
        $this->createSnapshot($paper, 30, '2026-10-06 11:29:59');
        $this->createSnapshot($inactive, 900, '2026-10-06 12:00:00');

        $analytics = (new ReadDashboardAnalytics)();

        $this->assertSame([
            'capturedAt' => '2026-10-06T12:00:00.000000Z',
            'windowStartedAt' => '2026-10-06T11:30:00.000000Z',
        ], $analytics['latestCollection']);
        $this->assertSame(
            [$modrinth->id, $fabric->id, $paper->id, $other->id],
            array_column($analytics['distributions'], 'id'),
        );
        $this->assertSame(['current', 'current', 'stale', 'missing'], array_column($analytics['distributions'], 'status'));
        $this->assertSame(['captured' => 2, 'available' => 4], $analytics['coverage']['distributions']);
        $this->assertSame(180, $analytics['totalDownloads']);
        $this->assertSame(30, $analytics['distributions'][2]['downloads']);
    }

    public function test_it_reads_version_rows_with_latest_downloads_status_and_default_order(): void
    {
        $distribution = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $older = $this->createVersion($distribution, 'v1', '2026-09-01 00:00:00');
        $unpublished = $this->createVersion($distribution, 'v-null', null);
        $newer = $this->createVersion($distribution, 'v2', '2026-10-01 00:00:00');
        $sameDate = $this->createVersion($distribution, 'v2b', '2026-10-01 00:00:00');

        $this->createSnapshot($distribution, 500, '2026-10-06 12:00:00');
        $this->createVersionSnapshot($older, 40, '2026-10-05 12:00:00');
        $this->createVersionSnapshot($older, 45, '2026-10-06 12:00:00');
        $this->createVersionSnapshot($older, 47, '2026-10-06 12:00:00');
        $this->createVersionSnapshot($newer, 10, '2026-10-06 11:30:00');
        $this->createVersionSnapshot($sameDate, 7, '2026-10-05 18:00:00');

        $analytics = (new ReadDashboardAnalytics)();
        $versions = $analytics['distributions'][0]['versions'];

        $this->assertSame([$sameDate->id, $newer->id, $older->id, $unpublished->id], array_column($versions, 'id'));
        $this->assertSame(['missing', 'current', 'current', 'missing'], array_column($versions, 'status'));
        $this->assertSame([7, 10, 47, null], array_column($versions, 'downloads'));
        $this->assertSame('2026-10-06T12:00:00.000000Z', $versions[2]['capturedAt']);
        $this->assertNull($versions[3]['capturedAt']);
        $this->assertSame('v1', $versions[2]['displayName']);
        $this->assertSame('1.0.0-v1', $versions[2]['versionNumber']);
        $this->assertSame(['fabric', 'paper'], $versions[2]['loaders']);
        $this->assertSame(['1.21.1'], $versions[2]['gameVersions']);
        $this->assertSame('2026-09-01T00:00:00.000000Z', $versions[2]['publishedAt']);
        $this->assertSame(['captured' => 2, 'available' => 4], $analytics['coverage']['versions']);
        $this->assertSame([], $analytics['distributions'][0]['removedVersions']);
    }

    public function test_it_calculates_version_growth_with_aggregate_growth_rules(): void
    {
        $distribution = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $established = $this->createVersion($distribution, 'established', '2026-08-01 00:00:00');
        $recent = $this->createVersion($distribution, 'recent', '2026-10-02 00:00:00');
        $corrected = $this->createVersion($distribution, 'corrected', '2026-07-01 00:00:00');
        $stopped = $this->createVersion($distribution, 'stopped', '2026-06-01 00:00:00');

        $this->createSnapshot($distribution, 5000, '2026-10-06 12:00:00');

        $this->createVersionSnapshot($established, 100, '2026-09-01 12:00:00');
        $this->createVersionSnapshot($established, 300, '2026-09-29 12:00:00');
        $this->createVersionSnapshot($established, 380, '2026-10-05 12:00:00');
        $this->createVersionSnapshot($established, 390, '2026-10-05 12:00:00');
        $this->createVersionSnapshot($established, 395, '2026-10-05 13:00:00');
        $this->createVersionSnapshot($established, 400, '2026-10-06 12:00:00');

        $this->createVersionSnapshot($recent, 20, '2026-10-02 12:00:00');
        $this->createVersionSnapshot($recent, 60, '2026-10-06 12:00:00');

        $this->createVersionSnapshot($corrected, 120, '2026-10-05 12:00:00');
        $this->createVersionSnapshot($corrected, 100, '2026-10-06 12:00:00');

        $this->createVersionSnapshot($stopped, 70, '2026-10-04 12:00:00');

        $analytics = (new ReadDashboardAnalytics)();
        $versions = collect($analytics['distributions'][0]['versions'])->keyBy('id');

        $establishedGrowth = $versions[$established->id]['growth'];
        $this->assertSame(['day', 'week', 'month'], array_column($establishedGrowth, 'key'));
        $this->assertSame([10, 100, 300], array_column($establishedGrowth, 'downloads'));
        $this->assertSame(['ready', 'ready', 'ready'], array_column($establishedGrowth, 'status'));
        $this->assertSame('2026-10-05T12:00:00.000000Z', $establishedGrowth[0]['baselineAt']);
        $this->assertSame('2026-10-06T12:00:00.000000Z', $establishedGrowth[0]['capturedAt']);

        $recentGrowth = $versions[$recent->id]['growth'];
        $this->assertSame([40, null, null], array_column($recentGrowth, 'downloads'));
        $this->assertSame(['ready', 'insufficient_history', 'insufficient_history'], array_column($recentGrowth, 'status'));
        $this->assertNull($recentGrowth[1]['baselineAt']);
        $this->assertSame('2026-10-06T12:00:00.000000Z', $recentGrowth[1]['capturedAt']);

        $this->assertSame(-20, $versions[$corrected->id]['growth'][0]['downloads']);
        $this->assertSame('ready', $versions[$corrected->id]['growth'][0]['status']);

        $this->assertSame('missing', $versions[$stopped->id]['status']);
        $this->assertSame([0, null, null], array_column($versions[$stopped->id]['growth'], 'downloads'));
        $this->assertSame('ready', $versions[$stopped->id]['growth'][0]['status']);
    }

    public function test_version_coverage_excludes_removed_versions_and_inactive_distributions(): void
    {
        $modrinth = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $fabric = $this->createDistribution('curseforge', 'CurseForge Fabric', 'fabric');
        $inactive = $this->createDistribution('curseforge', 'Inactive', 'paper', active: false);

        $current = $this->createVersion($modrinth, 'current', '2026-10-01 00:00:00');
        $missing = $this->createVersion($modrinth, 'missing', '2026-09-01 00:00:00');
        $removedEarlier = $this->createVersion($modrinth, 'removed-earlier', '2026-08-01 00:00:00', excludedAt: '2026-10-03 00:00:00');
        $removedLater = $this->createVersion($modrinth, 'removed-later', '2026-07-01 00:00:00', excludedAt: '2026-10-04 00:00:00');
        $staleVersion = $this->createVersion($fabric, 'stale', '2026-09-15 00:00:00');
        $inactiveVersion = $this->createVersion($inactive, 'inactive', '2026-10-01 00:00:00');

        $this->createSnapshot($modrinth, 500, '2026-10-06 12:00:00');
        $this->createSnapshot($fabric, 200, '2026-10-05 12:00:00');
        $this->createSnapshot($inactive, 50, '2026-10-06 12:00:00');
        $this->createVersionSnapshot($current, 10, '2026-10-06 12:00:00');
        $this->createVersionSnapshot($missing, 20, '2026-10-05 12:00:00');
        $this->createVersionSnapshot($removedEarlier, 30, '2026-10-02 12:00:00');
        $this->createVersionSnapshot($staleVersion, 40, '2026-10-05 12:00:00');
        $this->createVersionSnapshot($inactiveVersion, 50, '2026-10-06 12:00:00');

        $analytics = (new ReadDashboardAnalytics)();
        [$modrinthRow, $fabricRow] = $analytics['distributions'];

        $this->assertSame([$current->id, $missing->id], array_column($modrinthRow['versions'], 'id'));
        $this->assertSame(['current', 'missing'], array_column($modrinthRow['versions'], 'status'));
        $this->assertSame('stale', $fabricRow['status']);
        $this->assertSame(['missing'], array_column($fabricRow['versions'], 'status'));
        $this->assertSame(['captured' => 1, 'available' => 3], $analytics['coverage']['versions']);

        $this->assertSame([$removedLater->id, $removedEarlier->id], array_column($modrinthRow['removedVersions'], 'id'));
        $this->assertSame([
            'id' => $removedEarlier->id,
            'displayName' => 'removed-earlier',
            'versionNumber' => '1.0.0-removed-earlier',
            'loaders' => ['fabric', 'paper'],
            'gameVersions' => ['1.21.1'],
            'publishedAt' => '2026-08-01T00:00:00.000000Z',
            'downloads' => 30,
            'capturedAt' => '2026-10-02T12:00:00.000000Z',
            'excludedAt' => '2026-10-03T00:00:00.000000Z',
        ], $modrinthRow['removedVersions'][1]);
        $this->assertNull($modrinthRow['removedVersions'][0]['downloads']);
        $this->assertSame([], $fabricRow['removedVersions']);
    }

    public function test_versions_are_missing_when_no_collection_exists(): void
    {
        $distribution = $this->createDistribution('modrinth', 'Modrinth', 'combined');
        $this->createVersion($distribution, 'v1', '2026-10-01 00:00:00');

        $analytics = (new ReadDashboardAnalytics)();
        $version = $analytics['distributions'][0]['versions'][0];

        $this->assertSame(['capturedAt' => null, 'windowStartedAt' => null], $analytics['latestCollection']);
        $this->assertSame('missing', $version['status']);
        $this->assertSame(
            ['insufficient_history', 'insufficient_history', 'insufficient_history'],
            array_column($version['growth'], 'status'),
        );
        $this->assertSame(['captured' => 0, 'available' => 1], $analytics['coverage']['versions']);
    }

    private function createVersion(
        Distribution $distribution,
        string $name,
        ?string $publishedAt,
        ?string $excludedAt = null,
    ): DistributionVersion {
        return DistributionVersion::query()->create([
            'distribution_id' => $distribution->id,
            'provider_version_identifier' => "{$distribution->id}-{$name}",
            'version_number' => "1.0.0-{$name}",
            'display_name' => $name,
            'loaders' => ['fabric', 'paper'],
            'game_versions' => ['1.21.1'],
            'published_at' => $publishedAt === null ? null : CarbonImmutable::parse($publishedAt, 'UTC'),
            'excluded_at' => $excludedAt === null ? null : CarbonImmutable::parse($excludedAt, 'UTC'),
        ]);
    }

    private function createVersionSnapshot(
        DistributionVersion $version,
        int $downloads,
        string $capturedAt,
    ): DistributionVersionSnapshot {
        return DistributionVersionSnapshot::query()->create([
            'distribution_version_id' => $version->id,
            'downloads' => $downloads,
            'captured_at' => CarbonImmutable::parse($capturedAt, 'UTC'),
        ]);
    }

    private function createDistribution(
        string $provider,
        string $name,
        string $loader,
        bool $active = true,
    ): Distribution {
        return Distribution::query()->create([
            'provider' => $provider,
            'name' => $name,
            'loader' => $loader,
            'project_identifier' => "{$provider}-{$loader}",
            'listing_url' => "https://example.com/{$provider}/{$loader}",
            'active' => $active,
        ]);
    }

    private function createSnapshot(
        Distribution $distribution,
        int $downloads,
        string $capturedAt,
        ?int $followers = null,
        ?int $likes = null,
    ): MetricSnapshot {
        return MetricSnapshot::query()->create([
            'distribution_id' => $distribution->id,
            'downloads' => $downloads,
            'followers' => $followers,
            'likes' => $likes,
            'captured_at' => CarbonImmutable::parse($capturedAt, 'UTC'),
        ]);
    }
}
