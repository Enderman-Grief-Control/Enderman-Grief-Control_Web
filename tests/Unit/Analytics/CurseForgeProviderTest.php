<?php

namespace Tests\Unit\Analytics;

use App\Analytics\DistributionVersionMetrics;
use App\Analytics\ProjectMetrics;
use App\Analytics\Providers\CurseForgeProvider;
use App\Models\Distribution;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

class CurseForgeProviderTest extends TestCase
{
    public function test_it_maps_curseforge_mod_metrics_to_normalized_metrics(): void
    {
        Http::fake([
            'https://api.curseforge.test/v1/mods/1686338' => Http::response([
                'data' => [
                    'id' => 1686338,
                    'name' => 'Enderman Grief Control',
                    'downloadCount' => 1204,
                    'thumbsUpCount' => 7,
                ],
            ]),
        ]);

        config([
            'services.curseforge.base_url' => 'https://api.curseforge.test',
            'services.curseforge.api_key' => 'test-curseforge-key',
        ]);

        $metrics = app(CurseForgeProvider::class)->getProjectMetrics($this->distribution());

        $this->assertInstanceOf(ProjectMetrics::class, $metrics);
        $this->assertSame(1204, $metrics->downloads);
        $this->assertNull($metrics->followers);
        $this->assertNull($metrics->likes);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.curseforge.test/v1/mods/1686338'
            && $request->hasHeader('x-api-key', 'test-curseforge-key')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_it_maps_curseforge_mod_files_to_normalized_version_metrics(): void
    {
        Http::fake([
            'https://api.curseforge.test/v1/mods/1686338/files*' => Http::response([
                'data' => [
                    [
                        'id' => 98765,
                        'modId' => 1686338,
                        'displayName' => 'Enderman Grief Control Fabric 1.21.8',
                        'fileName' => 'enderman-grief-control-fabric.jar',
                        'releaseType' => 1,
                        'fileDate' => '2026-09-19T12:30:00Z',
                        'downloadCount' => 432,
                        'gameVersions' => ['1.21.8', 'Fabric'],
                    ],
                ],
                'pagination' => [
                    'index' => 0,
                    'pageSize' => 50,
                    'resultCount' => 1,
                    'totalCount' => 1,
                ],
            ]),
        ]);

        config([
            'services.curseforge.base_url' => 'https://api.curseforge.test',
            'services.curseforge.api_key' => 'test-curseforge-key',
        ]);

        $versions = app(CurseForgeProvider::class)->getVersionMetrics($this->distribution());

        $this->assertCount(1, $versions);
        $this->assertContainsOnlyInstancesOf(DistributionVersionMetrics::class, $versions);

        $version = $versions[0];

        $this->assertSame('98765', $version->providerVersionIdentifier);
        $this->assertNull($version->versionNumber);
        $this->assertSame('Enderman Grief Control Fabric 1.21.8', $version->displayName);
        $this->assertSame(['fabric'], $version->loaders);
        $this->assertSame(['1.21.8', 'Fabric'], $version->gameVersions);
        $this->assertSame(432, $version->downloads);
        $this->assertTrue(CarbonImmutable::parse('2026-09-19T12:30:00Z')->equalTo($version->publishedAt));
        $this->assertSame([
            'file_name' => 'enderman-grief-control-fabric.jar',
            'release_type' => 1,
        ], $version->metadata);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.curseforge.test/v1/mods/1686338/files')
            && $request->hasHeader('x-api-key', 'test-curseforge-key')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_it_maps_paginated_curseforge_mod_files(): void
    {
        Http::fake([
            'https://api.curseforge.test/v1/mods/1686338/files*' => Http::sequence()
                ->push([
                    'data' => [
                        [
                            'id' => 1001,
                            'displayName' => 'First file',
                            'fileDate' => '2026-09-19T12:30:00Z',
                            'downloadCount' => 100,
                            'gameVersions' => ['1.21.8'],
                        ],
                    ],
                    'pagination' => [
                        'index' => 0,
                        'pageSize' => 50,
                        'resultCount' => 1,
                        'totalCount' => 2,
                    ],
                ])
                ->push([
                    'data' => [
                        [
                            'id' => 1002,
                            'displayName' => 'Second file',
                            'fileDate' => '2026-09-20T12:30:00Z',
                            'downloadCount' => 200,
                            'gameVersions' => ['1.21.8'],
                        ],
                    ],
                    'pagination' => [
                        'index' => 1,
                        'pageSize' => 50,
                        'resultCount' => 1,
                        'totalCount' => 2,
                    ],
                ]),
        ]);

        config([
            'services.curseforge.base_url' => 'https://api.curseforge.test',
            'services.curseforge.api_key' => 'test-curseforge-key',
        ]);

        $versions = app(CurseForgeProvider::class)->getVersionMetrics($this->distribution());

        $this->assertSame(['1001', '1002'], array_map(
            fn (DistributionVersionMetrics $version): string => $version->providerVersionIdentifier,
            $versions,
        ));

        Http::assertSentCount(2);
    }

    public function test_it_fails_when_the_api_key_is_missing(): void
    {
        Http::fake();

        config([
            'services.curseforge.base_url' => 'https://api.curseforge.test',
            'services.curseforge.api_key' => null,
        ]);

        try {
            app(CurseForgeProvider::class)->getProjectMetrics($this->distribution());

            $this->fail('Expected the CurseForge provider to reject a missing API key.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('CurseForge API key must be configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_fails_on_unsuccessful_curseforge_responses(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'not found'], 404),
        ]);

        config(['services.curseforge.api_key' => 'test-curseforge-key']);

        $this->expectException(RequestException::class);

        app(CurseForgeProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_the_data_object_is_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 1686338,
                'downloadCount' => 1204,
            ]),
        ]);

        config(['services.curseforge.api_key' => 'test-curseforge-key']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('data');

        app(CurseForgeProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_required_metrics_are_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'id' => 1686338,
                ],
            ]),
        ]);

        config(['services.curseforge.api_key' => 'test-curseforge-key']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('downloadCount');

        app(CurseForgeProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_required_metrics_are_malformed(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'id' => 1686338,
                    'downloadCount' => '1204',
                ],
            ]),
        ]);

        config(['services.curseforge.api_key' => 'test-curseforge-key']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('downloadCount');

        app(CurseForgeProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_required_version_fields_are_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    [
                        'id' => 98765,
                        'displayName' => 'Enderman Grief Control Fabric 1.21.8',
                        'fileDate' => '2026-09-19T12:30:00Z',
                        'gameVersions' => ['1.21.8'],
                    ],
                ],
            ]),
        ]);

        config(['services.curseforge.api_key' => 'test-curseforge-key']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('downloadCount');

        app(CurseForgeProvider::class)->getVersionMetrics($this->distribution());
    }

    public function test_it_fails_when_required_version_fields_are_malformed(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    [
                        'id' => 98765,
                        'displayName' => 'Enderman Grief Control Fabric 1.21.8',
                        'fileDate' => '2026-09-19T12:30:00Z',
                        'downloadCount' => 432,
                        'gameVersions' => '1.21.8',
                    ],
                ],
            ]),
        ]);

        config(['services.curseforge.api_key' => 'test-curseforge-key']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('gameVersions');

        app(CurseForgeProvider::class)->getVersionMetrics($this->distribution());
    }

    private function distribution(): Distribution
    {
        return new Distribution([
            'provider' => 'curseforge',
            'name' => 'CurseForge Fabric',
            'loader' => 'fabric',
            'project_identifier' => '1686338',
            'listing_url' => 'https://www.curseforge.com/minecraft/mc-mods/enderman-grief-control',
            'active' => true,
        ]);
    }
}
