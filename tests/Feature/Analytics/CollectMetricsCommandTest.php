<?php

namespace Tests\Feature\Analytics;

use App\Models\Distribution;
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
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('metric_snapshots', [
            'distribution_id' => $distribution->id,
            'downloads' => 901,
            'followers' => 4,
            'likes' => null,
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
            'https://api.curseforge.com/v1/mods/1686338' => Http::response([
                'data' => [
                    'downloadCount' => 1204,
                ],
            ]),
            'https://api.curseforge.com/v1/mods/1686360' => Http::response([
                'data' => [
                    'downloadCount' => 402,
                ],
            ]),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
            ->expectsOutput('Collected curseforge/CurseForge Fabric: 1204 downloads.')
            ->expectsOutput('Collected curseforge/CurseForge Paper: 402 downloads.')
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
            'https://api.curseforge.com/v1/mods/1686338' => Http::response([], 500),
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
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
        ]);

        $this->artisan('metrics:collect')
            ->expectsOutput('Collected modrinth/Modrinth: 901 downloads.')
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
}
