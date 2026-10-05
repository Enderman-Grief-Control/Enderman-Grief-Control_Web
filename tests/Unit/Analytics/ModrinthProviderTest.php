<?php

namespace Tests\Unit\Analytics;

use App\Analytics\DistributionVersionMetrics;
use App\Analytics\ProjectMetrics;
use App\Analytics\Providers\ModrinthProvider;
use App\Models\Distribution;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

class ModrinthProviderTest extends TestCase
{
    public function test_it_maps_modrinth_project_metrics_to_normalized_metrics(): void
    {
        Http::fake([
            'https://api.modrinth.test/v2/project/6jCDxmNc' => Http::response([
                'id' => '6jCDxmNc',
                'slug' => 'enderman-grief-control',
                'downloads' => 697,
                'followers' => 1,
            ]),
        ]);

        config([
            'services.modrinth.base_url' => 'https://api.modrinth.test/v2',
            'services.modrinth.user_agent' => 'EndermanGriefControlWeb/Test',
        ]);

        $metrics = app(ModrinthProvider::class)->getProjectMetrics($this->distribution());

        $this->assertInstanceOf(ProjectMetrics::class, $metrics);
        $this->assertSame(697, $metrics->downloads);
        $this->assertSame(1, $metrics->followers);
        $this->assertNull($metrics->likes);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.modrinth.test/v2/project/6jCDxmNc'
            && $request->hasHeader('User-Agent', 'EndermanGriefControlWeb/Test')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_it_maps_modrinth_project_versions_to_normalized_version_metrics(): void
    {
        Http::fake([
            'https://api.modrinth.test/v2/project/6jCDxmNc/version' => Http::response([
                [
                    'id' => 'IIJJKKLL',
                    'name' => 'Enderman Grief Control Paper 1.21.8',
                    'version_number' => 'paper-1.21.8-1.0.0',
                    'game_versions' => ['1.21.8'],
                    'version_type' => 'release',
                    'loaders' => ['paper'],
                    'date_published' => '2026-09-19T12:30:00Z',
                    'downloads' => 321,
                ],
                [
                    'id' => 'MMNNOOPP',
                    'name' => 'Enderman Grief Control Fabric 1.21.8',
                    'version_number' => 'fabric-1.21.8-1.0.0',
                    'game_versions' => ['1.21.8'],
                    'loaders' => ['fabric'],
                    'date_published' => '2026-09-20T15:45:00Z',
                    'downloads' => 654,
                ],
            ]),
        ]);

        config([
            'services.modrinth.base_url' => 'https://api.modrinth.test/v2',
            'services.modrinth.user_agent' => 'EndermanGriefControlWeb/Test',
        ]);

        $versions = app(ModrinthProvider::class)->getVersionMetrics($this->distribution());

        $this->assertCount(2, $versions);
        $this->assertContainsOnlyInstancesOf(DistributionVersionMetrics::class, $versions);

        $paperVersion = $versions[0];

        $this->assertSame('IIJJKKLL', $paperVersion->providerVersionIdentifier);
        $this->assertSame('paper-1.21.8-1.0.0', $paperVersion->versionNumber);
        $this->assertSame('Enderman Grief Control Paper 1.21.8', $paperVersion->displayName);
        $this->assertSame(['paper'], $paperVersion->loaders);
        $this->assertSame(['1.21.8'], $paperVersion->gameVersions);
        $this->assertSame(321, $paperVersion->downloads);
        $this->assertTrue(CarbonImmutable::parse('2026-09-19T12:30:00Z')->equalTo($paperVersion->publishedAt));
        $this->assertSame([
            'version_type' => 'release',
        ], $paperVersion->metadata);

        $fabricVersion = $versions[1];

        $this->assertSame('MMNNOOPP', $fabricVersion->providerVersionIdentifier);
        $this->assertSame(['fabric'], $fabricVersion->loaders);
        $this->assertNull($fabricVersion->metadata);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.modrinth.test/v2/project/6jCDxmNc/version'
            && $request->hasHeader('User-Agent', 'EndermanGriefControlWeb/Test')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_it_fails_on_unsuccessful_modrinth_responses(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'not found'], 404),
        ]);

        $this->expectException(RequestException::class);

        app(ModrinthProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_required_metrics_are_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => '6jCDxmNc',
                'downloads' => 697,
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('followers');

        app(ModrinthProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_required_metrics_are_malformed(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => '6jCDxmNc',
                'downloads' => '697',
                'followers' => 1,
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('downloads');

        app(ModrinthProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_required_version_fields_are_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                [
                    'name' => 'Enderman Grief Control Paper 1.21.8',
                    'version_number' => 'paper-1.21.8-1.0.0',
                    'game_versions' => ['1.21.8'],
                    'loaders' => ['paper'],
                    'date_published' => '2026-09-19T12:30:00Z',
                    'downloads' => 321,
                ],
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('id');

        app(ModrinthProvider::class)->getVersionMetrics($this->distribution());
    }

    public function test_it_fails_when_required_version_fields_are_malformed(): void
    {
        Http::fake([
            '*' => Http::response([
                [
                    'id' => 'IIJJKKLL',
                    'name' => 'Enderman Grief Control Paper 1.21.8',
                    'version_number' => 'paper-1.21.8-1.0.0',
                    'game_versions' => '1.21.8',
                    'loaders' => ['paper'],
                    'date_published' => '2026-09-19T12:30:00Z',
                    'downloads' => 321,
                ],
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('game_versions');

        app(ModrinthProvider::class)->getVersionMetrics($this->distribution());
    }

    private function distribution(): Distribution
    {
        return new Distribution([
            'provider' => 'modrinth',
            'name' => 'Modrinth',
            'loader' => 'combined',
            'project_identifier' => '6jCDxmNc',
            'listing_url' => 'https://modrinth.com/plugin/enderman-grief-control',
            'active' => true,
        ]);
    }
}
