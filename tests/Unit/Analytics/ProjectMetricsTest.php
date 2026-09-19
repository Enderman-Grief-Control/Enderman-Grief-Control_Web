<?php

namespace Tests\Unit\Analytics;

use App\Analytics\Contracts\DistributionProvider;
use App\Analytics\ProjectMetrics;
use App\Models\Distribution;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class ProjectMetricsTest extends TestCase
{
    public function test_project_metrics_preserve_normalized_values(): void
    {
        $capturedAt = CarbonImmutable::parse('2026-09-19 12:34:56');

        $metrics = new ProjectMetrics(
            downloads: 123,
            followers: 45,
            likes: null,
            capturedAt: $capturedAt,
        );

        $this->assertSame(123, $metrics->downloads);
        $this->assertSame(45, $metrics->followers);
        $this->assertNull($metrics->likes);
        $this->assertTrue($capturedAt->equalTo($metrics->capturedAt));
    }

    public function test_project_metrics_default_captured_at_to_now(): void
    {
        CarbonImmutable::setTestNow('2026-09-19 13:00:00');

        try {
            $metrics = new ProjectMetrics(downloads: 1);

            $this->assertTrue(CarbonImmutable::now()->equalTo($metrics->capturedAt));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_project_metrics_reject_negative_required_metrics(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('downloads');

        new ProjectMetrics(downloads: -1);
    }

    public function test_project_metrics_reject_negative_nullable_metrics(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('followers');

        new ProjectMetrics(downloads: 1, followers: -1);
    }

    public function test_distribution_provider_contract_returns_normalized_metrics(): void
    {
        $provider = new class implements DistributionProvider
        {
            public function getProjectMetrics(Distribution $distribution): ProjectMetrics
            {
                return new ProjectMetrics(downloads: 10, likes: 2);
            }
        };

        $metrics = $provider->getProjectMetrics(new Distribution([
            'provider' => 'fake',
            'name' => 'Fake Provider',
            'loader' => 'combined',
            'project_identifier' => 'fake-project',
            'listing_url' => 'https://example.com/fake-project',
            'active' => true,
        ]));

        $this->assertSame(10, $metrics->downloads);
        $this->assertNull($metrics->followers);
        $this->assertSame(2, $metrics->likes);
    }
}
