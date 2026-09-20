<?php

namespace Tests\Feature\Analytics;

use App\Models\Distribution;
use Database\Seeders\DistributionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribution_seeder_writes_canonical_modrinth_listing(): void
    {
        $this->seed(DistributionSeeder::class);

        $this->assertDatabaseHas('distributions', [
            'provider' => 'modrinth',
            'name' => 'Modrinth',
            'loader' => 'combined',
            'project_identifier' => '6jCDxmNc',
            'listing_url' => 'https://modrinth.com/plugin/enderman-grief-control',
            'active' => true,
        ]);
    }

    public function test_distribution_seeder_updates_existing_modrinth_listing_idempotently(): void
    {
        Distribution::query()->create([
            'provider' => 'modrinth',
            'name' => 'Old Modrinth',
            'loader' => 'combined',
            'project_identifier' => 'old-project',
            'listing_url' => 'https://example.com/old-modrinth-listing',
            'active' => false,
        ]);

        $this->seed(DistributionSeeder::class);

        $this->assertSame(3, Distribution::query()->count());

        $this->assertDatabaseHas('distributions', [
            'provider' => 'modrinth',
            'name' => 'Modrinth',
            'loader' => 'combined',
            'project_identifier' => '6jCDxmNc',
            'listing_url' => 'https://modrinth.com/plugin/enderman-grief-control',
            'active' => true,
        ]);

        $this->assertDatabaseMissing('distributions', [
            'provider' => 'modrinth',
            'loader' => 'combined',
            'project_identifier' => 'old-project',
        ]);
    }
}
