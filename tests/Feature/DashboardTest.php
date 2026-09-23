<?php

namespace Tests\Feature;

use App\Models\Distribution;
use App\Models\MetricSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard_with_analytics_props(): void
    {
        $user = User::factory()->create();
        $distribution = Distribution::query()->create([
            'provider' => 'modrinth',
            'name' => 'Modrinth',
            'loader' => 'combined',
            'project_identifier' => 'modrinth-combined',
            'listing_url' => 'https://modrinth.com/plugin/enderman-grief-control',
            'active' => true,
        ]);

        MetricSnapshot::query()->create([
            'distribution_id' => $distribution->id,
            'downloads' => 1234,
            'followers' => 56,
            'likes' => 78,
            'captured_at' => CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'),
        ]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('analytics', fn (Assert $page) => $page
                    ->where('totalDownloads', 1234)
                    ->where('lastUpdatedAt', '2026-09-20T10:00:00.000000Z')
                    ->where('hasSnapshots', true)
                    ->where('hasMissingSnapshots', false)
                    ->has('distributions', 1)
                    ->where('distributions.0.id', $distribution->id)
                    ->where('distributions.0.provider', 'modrinth')
                    ->where('distributions.0.name', 'Modrinth')
                    ->where('distributions.0.loader', 'combined')
                    ->where('distributions.0.listingUrl', 'https://modrinth.com/plugin/enderman-grief-control')
                    ->where('distributions.0.downloads', 1234)
                    ->where('distributions.0.followers', 56)
                    ->where('distributions.0.likes', 78)
                    ->where('distributions.0.capturedAt', '2026-09-20T10:00:00.000000Z')
                    ->where('distributions.0.status', 'current')
                )
            );
    }

    public function test_authenticated_dashboard_includes_missing_distribution_state(): void
    {
        $user = User::factory()->create();

        Distribution::query()->create([
            'provider' => 'curseforge',
            'name' => 'CurseForge Fabric',
            'loader' => 'fabric',
            'project_identifier' => 'curseforge-fabric',
            'listing_url' => 'https://www.curseforge.com/minecraft/mc-mods/enderman-grief-control',
            'active' => true,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('analytics', fn (Assert $page) => $page
                    ->where('totalDownloads', 0)
                    ->where('lastUpdatedAt', null)
                    ->where('hasSnapshots', false)
                    ->where('hasMissingSnapshots', true)
                    ->has('distributions', 1)
                    ->where('distributions.0.status', 'missing')
                    ->where('distributions.0.downloads', null)
                    ->where('distributions.0.capturedAt', null)
                )
            );
    }
}
