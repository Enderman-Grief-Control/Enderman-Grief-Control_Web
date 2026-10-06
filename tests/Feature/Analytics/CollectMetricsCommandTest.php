<?php

namespace Tests\Feature\Analytics;

use App\Models\Distribution;
use App\Models\DistributionVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_collect_command_stores_modrinth_snapshot(): void
    {
        $distribution = $this->createModrinthDistribution();

        Http::fake([
            'https://api.modrinth.com/v2/project/6jCDxmNc' => Http::response([
                'downloads' => 901,
                'followers' => 4,
            ]),
            'https://api.modrinth.com/v2/project/6jCDxmNc/version' => Http::response([
                $this->modrinthVersionPayload(downloads: 321),
            ]),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
            ->expectsOutput('Collected modrinth/Modrinth details: 1 version/file snapshots.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('metric_snapshots', [
            'distribution_id' => $distribution->id,
            'downloads' => 901,
            'followers' => 4,
            'likes' => null,
        ]);

        $version = DistributionVersion::query()->firstOrFail();

        $this->assertSame($distribution->id, $version->distribution_id);
        $this->assertSame('IIJJKKLL', $version->provider_version_identifier);
        $this->assertSame(['paper'], $version->loaders);
        $this->assertSame(['1.21.8'], $version->game_versions);

        $this->assertDatabaseHas('distribution_version_snapshots', [
            'distribution_version_id' => $version->id,
            'downloads' => 321,
        ]);
    }

    public function test_metrics_collect_command_stores_snapshots_for_all_supported_distributions(): void
    {
        $modrinth = $this->createModrinthDistribution();
        $curseForgeFabric = $this->createCurseForgeFabricDistribution();
        $curseForgePaper = $this->createCurseForgePaperDistribution();

        config(['services.curseforge.api_key' => 'test-curseforge-key']);

        Http::fake([
            'https://api.modrinth.com/v2/project/6jCDxmNc' => Http::response([
                'downloads' => 901,
                'followers' => 4,
            ]),
            'https://api.modrinth.com/v2/project/6jCDxmNc/version' => Http::response([
                $this->modrinthVersionPayload(providerVersionIdentifier: 'IIJJKKLL', downloads: 321),
            ]),
            'https://api.curseforge.com/v1/mods/1686338' => Http::response([
                'data' => [
                    'downloadCount' => 1204,
                ],
            ]),
            'https://api.curseforge.com/v1/mods/1686338/files*' => Http::response($this->curseForgeFilesPayload(
                providerVersionIdentifier: 98765,
                displayName: 'Enderman Grief Control Fabric 1.21.8',
                downloads: 432,
            )),
            'https://api.curseforge.com/v1/mods/1686360' => Http::response([
                'data' => [
                    'downloadCount' => 402,
                ],
            ]),
            'https://api.curseforge.com/v1/mods/1686360/files*' => Http::response($this->curseForgeFilesPayload(
                providerVersionIdentifier: 98766,
                displayName: 'Enderman Grief Control Paper 1.21.8',
                downloads: 111,
            )),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
            ->expectsOutput('Collected modrinth/Modrinth details: 1 version/file snapshots.')
            ->expectsOutput('Collected curseforge/CurseForge Fabric: 1204 downloads.')
            ->expectsOutput('Collected curseforge/CurseForge Fabric details: 1 version/file snapshots.')
            ->expectsOutput('Collected curseforge/CurseForge Paper: 402 downloads.')
            ->expectsOutput('Collected curseforge/CurseForge Paper details: 1 version/file snapshots.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('metric_snapshots', [
            'distribution_id' => $modrinth->id,
            'downloads' => 901,
            'followers' => 4,
            'likes' => null,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'distribution_id' => $curseForgeFabric->id,
            'downloads' => 1204,
            'followers' => null,
            'likes' => null,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'distribution_id' => $curseForgePaper->id,
            'downloads' => 402,
            'followers' => null,
            'likes' => null,
        ]);

        $this->assertDatabaseCount('distribution_versions', 3);
        $this->assertDatabaseCount('distribution_version_snapshots', 3);
    }

    public function test_metrics_collect_command_does_not_store_snapshot_when_provider_fails(): void
    {
        $distribution = $this->createModrinthDistribution();

        Http::fake([
            'https://api.modrinth.com/v2/project/6jCDxmNc' => Http::response([], 500),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutputToContain('Failed to collect modrinth/Modrinth:')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('metric_snapshots', [
            'distribution_id' => $distribution->id,
        ]);
    }

    public function test_metrics_collect_command_does_not_store_curseforge_snapshot_when_curseforge_fails(): void
    {
        $modrinth = $this->createModrinthDistribution();
        $curseForgeFabric = $this->createCurseForgeFabricDistribution();

        config(['services.curseforge.api_key' => 'test-curseforge-key']);

        Http::fake([
            'https://api.modrinth.com/v2/project/6jCDxmNc' => Http::response([
                'downloads' => 901,
                'followers' => 4,
            ]),
            'https://api.modrinth.com/v2/project/6jCDxmNc/version' => Http::response([
                $this->modrinthVersionPayload(),
            ]),
            'https://api.curseforge.com/v1/mods/1686338' => Http::response([], 500),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
            ->expectsOutput('Collected modrinth/Modrinth details: 1 version/file snapshots.')
            ->expectsOutputToContain('Failed to collect curseforge/CurseForge Fabric:')
            ->assertExitCode(1);

        $this->assertDatabaseHas('metric_snapshots', [
            'distribution_id' => $modrinth->id,
            'downloads' => 901,
        ]);

        $this->assertDatabaseMissing('metric_snapshots', [
            'distribution_id' => $curseForgeFabric->id,
        ]);
    }

    public function test_metrics_collect_command_fails_clearly_when_curseforge_api_key_is_missing(): void
    {
        $modrinth = $this->createModrinthDistribution();
        $curseForgeFabric = $this->createCurseForgeFabricDistribution();

        config(['services.curseforge.api_key' => null]);

        Http::fake([
            'https://api.modrinth.com/v2/project/6jCDxmNc' => Http::response([
                'downloads' => 901,
                'followers' => 4,
            ]),
            'https://api.modrinth.com/v2/project/6jCDxmNc/version' => Http::response([
                $this->modrinthVersionPayload(),
            ]),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
            ->expectsOutput('Collected modrinth/Modrinth details: 1 version/file snapshots.')
            ->expectsOutput('Failed to collect curseforge/CurseForge Fabric: CurseForge API key must be configured.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('metric_snapshots', [
            'distribution_id' => $modrinth->id,
            'downloads' => 901,
        ]);

        $this->assertDatabaseMissing('metric_snapshots', [
            'distribution_id' => $curseForgeFabric->id,
        ]);
    }

    public function test_metrics_collect_command_updates_version_identities_and_appends_detailed_snapshots(): void
    {
        $distribution = $this->createModrinthDistribution();

        Http::fake([
            'https://api.modrinth.com/v2/project/6jCDxmNc' => Http::sequence()
                ->push([
                    'downloads' => 901,
                    'followers' => 4,
                ])
                ->push([
                    'downloads' => 950,
                    'followers' => 5,
                ]),
            'https://api.modrinth.com/v2/project/6jCDxmNc/version' => Http::sequence()
                ->push([
                    $this->modrinthVersionPayload(
                        displayName: 'Enderman Grief Control Paper 1.21.8',
                        downloads: 321,
                    ),
                ])
                ->push([
                    $this->modrinthVersionPayload(
                        displayName: 'Enderman Grief Control Paper 1.21.8 Rev 2',
                        downloads: 333,
                    ),
                ]),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
            ->expectsOutput('Collected modrinth/Modrinth details: 1 version/file snapshots.')
            ->assertExitCode(0);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 950 downloads.')
            ->expectsOutput('Collected modrinth/Modrinth details: 1 version/file snapshots.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('metric_snapshots', 2);
        $this->assertDatabaseCount('distribution_versions', 1);
        $this->assertDatabaseCount('distribution_version_snapshots', 2);

        $version = DistributionVersion::query()->firstOrFail();

        $this->assertSame($distribution->id, $version->distribution_id);
        $this->assertSame('IIJJKKLL', $version->provider_version_identifier);
        $this->assertSame('Enderman Grief Control Paper 1.21.8 Rev 2', $version->display_name);
        $this->assertSame([321, 333], $version->snapshots()->orderBy('id')->pluck('downloads')->all());
    }

    public function test_metrics_collect_command_preserves_aggregate_snapshot_when_detailed_collection_fails(): void
    {
        $distribution = $this->createModrinthDistribution();

        Http::fake([
            'https://api.modrinth.com/v2/project/6jCDxmNc' => Http::response([
                'downloads' => 901,
                'followers' => 4,
            ]),
            'https://api.modrinth.com/v2/project/6jCDxmNc/version' => Http::response([], 500),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
            ->expectsOutputToContain('Failed to collect detailed metrics for modrinth/Modrinth:')
            ->assertExitCode(1);

        $this->assertDatabaseHas('metric_snapshots', [
            'distribution_id' => $distribution->id,
            'downloads' => 901,
            'followers' => 4,
            'likes' => null,
        ]);

        $this->assertDatabaseCount('distribution_versions', 0);
        $this->assertDatabaseCount('distribution_version_snapshots', 0);
    }

    public function test_metrics_collect_command_fails_when_no_active_supported_distribution_exists(): void
    {
        $this->artisan('metrics:collect')
            ->expectsOutput('No active supported distributions were found.')
            ->assertExitCode(1);
    }

    private function createModrinthDistribution(): Distribution
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

    private function createCurseForgeFabricDistribution(): Distribution
    {
        return Distribution::query()->create([
            'provider' => 'curseforge',
            'name' => 'CurseForge Fabric',
            'loader' => 'fabric',
            'project_identifier' => '1686338',
            'listing_url' => 'https://www.curseforge.com/minecraft/mc-mods/enderman-grief-control',
            'active' => true,
        ]);
    }

    private function createCurseForgePaperDistribution(): Distribution
    {
        return Distribution::query()->create([
            'provider' => 'curseforge',
            'name' => 'CurseForge Paper',
            'loader' => 'paper',
            'project_identifier' => '1686360',
            'listing_url' => 'https://www.curseforge.com/minecraft/bukkit-plugins/enderman-grief-control',
            'active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function modrinthVersionPayload(
        string $providerVersionIdentifier = 'IIJJKKLL',
        string $displayName = 'Enderman Grief Control Paper 1.21.8',
        int $downloads = 321,
    ): array {
        return [
            'id' => $providerVersionIdentifier,
            'name' => $displayName,
            'version_number' => 'paper-1.21.8-1.0.0',
            'game_versions' => ['1.21.8'],
            'version_type' => 'release',
            'loaders' => ['paper'],
            'date_published' => '2026-09-19T12:30:00Z',
            'downloads' => $downloads,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function curseForgeFilesPayload(
        int $providerVersionIdentifier,
        string $displayName,
        int $downloads,
    ): array {
        return [
            'data' => [
                [
                    'id' => $providerVersionIdentifier,
                    'displayName' => $displayName,
                    'fileDate' => '2026-09-19T12:30:00Z',
                    'downloadCount' => $downloads,
                    'gameVersions' => ['1.21.8'],
                ],
            ],
            'pagination' => [
                'index' => 0,
                'pageSize' => 50,
                'resultCount' => 1,
                'totalCount' => 1,
            ],
        ];
    }
}
