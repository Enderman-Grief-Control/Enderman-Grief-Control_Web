<?php

namespace Tests\Feature\Analytics;

use App\Analytics\ReadDashboardAnalytics;
use App\Models\Distribution;
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
