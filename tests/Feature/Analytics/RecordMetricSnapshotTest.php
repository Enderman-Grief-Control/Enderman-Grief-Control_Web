<?php

namespace Tests\Feature\Analytics;

use App\Analytics\Contracts\DistributionProvider;
use App\Analytics\ProjectMetrics;
use App\Analytics\RecordMetricSnapshot;
use App\Models\Distribution;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordMetricSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_provider_result_can_be_persisted_as_metric_snapshot(): void
    {
        $distribution = $this->createDistribution();
        $capturedAt = CarbonImmutable::parse('2026-09-19 14:30:00');
        $provider = new class($capturedAt) implements DistributionProvider
        {
            public function __construct(private readonly CarbonImmutable $capturedAt) {}

            public function getProjectMetrics(Distribution $distribution): ProjectMetrics
            {
                return new ProjectMetrics(
                    downloads: 1234,
                    followers: 56,
                    likes: 78,
                    capturedAt: $this->capturedAt,
                );
            }
        };

        $snapshot = (new RecordMetricSnapshot)(
            $distribution,
            $provider->getProjectMetrics($distribution),
        );

        $this->assertSame($distribution->id, $snapshot->distribution_id);
        $this->assertSame(1234, $snapshot->downloads);
        $this->assertSame(56, $snapshot->followers);
        $this->assertSame(78, $snapshot->likes);
        $this->assertTrue($capturedAt->equalTo($snapshot->captured_at));
        $this->assertTrue($distribution->is($snapshot->distribution));

        $this->assertDatabaseHas('metric_snapshots', [
            'id' => $snapshot->id,
            'distribution_id' => $distribution->id,
            'downloads' => 1234,
            'followers' => 56,
            'likes' => 78,
        ]);
    }

    public function test_nullable_metrics_remain_nullable_when_snapshot_is_persisted(): void
    {
        $distribution = $this->createDistribution();

        $snapshot = (new RecordMetricSnapshot)(
            $distribution,
            new ProjectMetrics(downloads: 20),
        );

        $this->assertNull($snapshot->followers);
        $this->assertNull($snapshot->likes);

        $this->assertDatabaseHas('metric_snapshots', [
            'id' => $snapshot->id,
            'followers' => null,
            'likes' => null,
        ]);
    }

    public function test_repeated_records_append_metric_snapshots(): void
    {
        $distribution = $this->createDistribution();
        $recordMetricSnapshot = new RecordMetricSnapshot;

        $recordMetricSnapshot($distribution, new ProjectMetrics(downloads: 20));
        $recordMetricSnapshot($distribution, new ProjectMetrics(downloads: 20));

        $this->assertCount(2, $distribution->refresh()->metricSnapshots);
    }

    private function createDistribution(): Distribution
    {
        return Distribution::query()->create([
            'provider' => 'fake',
            'name' => 'Fake Provider',
            'loader' => 'combined',
            'project_identifier' => 'fake-project',
            'listing_url' => 'https://example.com/fake-project',
            'active' => true,
        ]);
    }
}
