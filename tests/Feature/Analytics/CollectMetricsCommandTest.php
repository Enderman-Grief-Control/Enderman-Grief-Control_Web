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

    public function test_metrics_collect_command_fails_when_no_active_modrinth_distribution_exists(): void
    {
        $this->artisan('metrics:collect')
            ->expectsOutput('No active Modrinth distribution was found.')
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
}
