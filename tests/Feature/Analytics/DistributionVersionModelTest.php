<?php

namespace Tests\Feature\Analytics;

use App\Models\Distribution;
use App\Models\DistributionVersion;
use App\Models\DistributionVersionSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributionVersionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribution_version_identity_can_be_created_for_a_distribution(): void
    {
        $distribution = $this->createDistribution();
        $publishedAt = CarbonImmutable::parse('2026-09-19 12:30:00', 'UTC');

        $version = DistributionVersion::query()->create([
            'distribution_id' => $distribution->id,
            'provider_version_identifier' => 'modrinth-version-123',
            'version_number' => '1.21.8-1.0.0',
            'display_name' => 'Enderman Grief Control 1.21.8',
            'loaders' => ['paper', 'fabric'],
            'game_versions' => ['1.21.8'],
            'published_at' => $publishedAt,
            'metadata' => ['channel' => 'release'],
        ]);

        $this->assertTrue($distribution->is($version->distribution));
        $this->assertTrue($publishedAt->equalTo($version->published_at));
        $this->assertSame(['paper', 'fabric'], $version->loaders);
        $this->assertSame(['1.21.8'], $version->game_versions);
        $this->assertSame(['channel' => 'release'], $version->metadata);

        $relatedVersion = $distribution->refresh()->versions->first();

        $this->assertNotNull($relatedVersion);
        $this->assertTrue($relatedVersion->is($version));

        $this->assertDatabaseHas('distribution_versions', [
            'id' => $version->id,
            'distribution_id' => $distribution->id,
            'provider_version_identifier' => 'modrinth-version-123',
            'version_number' => '1.21.8-1.0.0',
            'display_name' => 'Enderman Grief Control 1.21.8',
        ]);
    }

    public function test_distribution_version_snapshots_can_be_appended(): void
    {
        $version = $this->createVersion();
        $firstCapturedAt = CarbonImmutable::parse('2026-09-20 01:00:00', 'UTC');
        $secondCapturedAt = CarbonImmutable::parse('2026-09-20 07:00:00', 'UTC');

        $firstSnapshot = DistributionVersionSnapshot::query()->create([
            'distribution_version_id' => $version->id,
            'downloads' => 100,
            'captured_at' => $firstCapturedAt,
        ]);

        $secondSnapshot = $version->snapshots()->create([
            'downloads' => 125,
            'captured_at' => $secondCapturedAt,
        ]);

        $this->assertTrue($version->is($firstSnapshot->distributionVersion));
        $this->assertSame(100, $firstSnapshot->downloads);
        $this->assertTrue($firstCapturedAt->equalTo($firstSnapshot->captured_at));
        $this->assertTrue($secondCapturedAt->equalTo($secondSnapshot->captured_at));
        $this->assertCount(2, $version->refresh()->snapshots);

        $this->assertDatabaseHas('distribution_version_snapshots', [
            'id' => $secondSnapshot->id,
            'distribution_version_id' => $version->id,
            'downloads' => 125,
        ]);
    }

    public function test_distribution_versions_are_available_until_excluded(): void
    {
        $version = $this->createVersion();

        $this->assertNull($version->refresh()->excluded_at);
        $this->assertTrue($version->isAvailable());
        $this->assertTrue(DistributionVersion::query()->available()->whereKey($version->id)->exists());
        $this->assertFalse(DistributionVersion::query()->excluded()->whereKey($version->id)->exists());

        $excludedAt = CarbonImmutable::parse('2026-09-21 08:00:00', 'UTC');
        $version->update(['excluded_at' => $excludedAt]);
        $version->snapshots()->create([
            'downloads' => 100,
            'captured_at' => CarbonImmutable::parse('2026-09-20 01:00:00', 'UTC'),
        ]);

        $version->refresh();

        $this->assertTrue($excludedAt->equalTo($version->excluded_at));
        $this->assertFalse($version->isAvailable());
        $this->assertFalse(DistributionVersion::query()->available()->whereKey($version->id)->exists());
        $this->assertTrue(DistributionVersion::query()->excluded()->whereKey($version->id)->exists());
        $this->assertCount(1, $version->snapshots);
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

    private function createVersion(): DistributionVersion
    {
        return DistributionVersion::query()->create([
            'distribution_id' => $this->createDistribution()->id,
            'provider_version_identifier' => 'modrinth-version-123',
            'version_number' => '1.21.8-1.0.0',
            'display_name' => 'Enderman Grief Control 1.21.8',
            'loaders' => ['paper'],
            'game_versions' => ['1.21.8'],
            'published_at' => CarbonImmutable::parse('2026-09-19 12:30:00', 'UTC'),
            'metadata' => null,
        ]);
    }
}
