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
