<?php

namespace Tests\Feature;

use App\Models\Distribution;
use App\Models\DistributionVersion;
use App\Models\DistributionVersionSnapshot;
use App\Models\MetricSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep route/data assertions independent of a running Vite SSR server.
        config(['inertia.ssr.enabled' => false]);

        // Dashboard rendering must read stored snapshots only, never provider APIs.
        Http::preventStrayRequests();
    }

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
            'downloads' => 1000,
            'followers' => 50,
            'likes' => 70,
            'captured_at' => CarbonImmutable::parse('2026-09-19 10:00:00', 'UTC'),
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
                    ->has('growth', fn (Assert $growth) => $growth
                        ->has('ranges', 3)
                        ->has('ranges.0', fn (Assert $range) => $range
                            ->where('key', 'day')
                            ->where('label', '24h')
                            ->where('hours', 24)
                            ->where('downloads', 234)
                            ->where('status', 'ready')
                            ->where('capturedAt', '2026-09-20T10:00:00.000000Z')
                            ->where('rangeStartedAt', '2026-09-19T10:00:00.000000Z')
                            ->where('missingDistributionIds', [])
                        )
                        ->has('ranges.1', fn (Assert $range) => $range
                            ->where('key', 'week')
                            ->where('label', '7d')
                            ->where('hours', 168)
                            ->where('downloads', null)
                            ->where('status', 'insufficient_history')
                            ->where('capturedAt', '2026-09-20T10:00:00.000000Z')
                            ->where('rangeStartedAt', '2026-09-13T10:00:00.000000Z')
                            ->where('missingDistributionIds', [$distribution->id])
                        )
                        ->where('ranges.2.key', 'month')
                        ->where('ranges.2.status', 'insufficient_history')
                    )
                    ->where('latestCollection', [
                        'capturedAt' => '2026-09-20T10:00:00.000000Z',
                        'windowStartedAt' => '2026-09-20T09:30:00.000000Z',
                    ])
                    ->where('coverage', [
                        'distributions' => ['captured' => 1, 'available' => 1],
                        'versions' => ['captured' => 0, 'available' => 0],
                    ])
                    ->has('distributions', 1)
                    ->has('distributions.0', fn (Assert $row) => $row
                        ->where('id', $distribution->id)
                        ->where('provider', 'modrinth')
                        ->where('name', 'Modrinth')
                        ->where('loader', 'combined')
                        ->where('listingUrl', 'https://modrinth.com/plugin/enderman-grief-control')
                        ->where('downloads', 1234)
                        ->where('followers', 56)
                        ->where('likes', 78)
                        ->where('capturedAt', '2026-09-20T10:00:00.000000Z')
                        ->where('status', 'current')
                        ->has('growth', 3)
                        ->has('growth.0', fn (Assert $range) => $range
                            ->where('key', 'day')
                            ->where('label', '24h')
                            ->where('hours', 24)
                            ->where('downloads', 234)
                            ->where('status', 'ready')
                            ->where('capturedAt', '2026-09-20T10:00:00.000000Z')
                            ->where('baselineAt', '2026-09-19T10:00:00.000000Z')
                        )
                        ->has('growth.1', fn (Assert $range) => $range
                            ->where('key', 'week')
                            ->where('label', '7d')
                            ->where('hours', 168)
                            ->where('downloads', null)
                            ->where('status', 'insufficient_history')
                            ->where('capturedAt', '2026-09-20T10:00:00.000000Z')
                            ->where('baselineAt', null)
                        )
                        ->where('growth.2.key', 'month')
                        ->where('growth.2.status', 'insufficient_history')
                        ->where('versions', [])
                        ->where('removedVersions', [])
                    )
                )
            );
    }

    public function test_authenticated_dashboard_includes_version_rows_and_removed_versions(): void
    {
        $user = User::factory()->create();
        $anchorAt = CarbonImmutable::parse('2026-10-08 13:37:00', 'UTC');

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
            'downloads' => 500,
            'captured_at' => $anchorAt,
        ]);

        $current = DistributionVersion::query()->create([
            'distribution_id' => $distribution->id,
            'provider_version_identifier' => 'current-version',
            'version_number' => '1.1.0',
            'display_name' => 'Enderman Grief Control 1.1.0',
            'loaders' => ['paper', 'fabric'],
            'game_versions' => ['1.21.8'],
            'published_at' => CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'),
        ]);
        $missing = DistributionVersion::query()->create([
            'distribution_id' => $distribution->id,
            'provider_version_identifier' => 'missing-version',
            'version_number' => '1.0.0',
            'display_name' => 'Enderman Grief Control 1.0.0',
            'loaders' => ['paper'],
            'game_versions' => ['1.21.7'],
            'published_at' => CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC'),
        ]);
        $removed = DistributionVersion::query()->create([
            'distribution_id' => $distribution->id,
            'provider_version_identifier' => 'removed-version',
            'version_number' => '0.9.0',
            'display_name' => 'Enderman Grief Control 0.9.0',
            'loaders' => ['paper'],
            'game_versions' => ['1.21.6'],
            'published_at' => CarbonImmutable::parse('2026-08-01 12:00:00', 'UTC'),
            'excluded_at' => CarbonImmutable::parse('2026-10-08 14:00:00', 'UTC'),
        ]);

        foreach ([
            [$current, 80, $anchorAt->subDay()],
            [$current, 120, $anchorAt],
            [$missing, 40, $anchorAt->subHours(8)],
            [$removed, 10, $anchorAt->subDays(2)],
        ] as [$version, $downloads, $capturedAt]) {
            DistributionVersionSnapshot::query()->create([
                'distribution_version_id' => $version->id,
                'downloads' => $downloads,
                'captured_at' => $capturedAt,
            ]);
        }

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('analytics.coverage', [
                    'distributions' => ['captured' => 1, 'available' => 1],
                    'versions' => ['captured' => 1, 'available' => 2],
                ])
                ->has('analytics.distributions.0.versions', 2)
                ->has('analytics.distributions.0.versions.0', fn (Assert $row) => $row
                    ->where('id', $current->id)
                    ->where('displayName', 'Enderman Grief Control 1.1.0')
                    ->where('versionNumber', '1.1.0')
                    ->where('loaders', ['paper', 'fabric'])
                    ->where('gameVersions', ['1.21.8'])
                    ->where('publishedAt', '2026-10-01T12:00:00.000000Z')
                    ->where('downloads', 120)
                    ->where('capturedAt', '2026-10-08T13:37:00.000000Z')
                    ->where('status', 'current')
                    ->has('growth', 3)
                    ->has('growth.0', fn (Assert $range) => $range
                        ->where('key', 'day')
                        ->where('label', '24h')
                        ->where('hours', 24)
                        ->where('downloads', 40)
                        ->where('status', 'ready')
                        ->where('capturedAt', '2026-10-08T13:37:00.000000Z')
                        ->where('baselineAt', '2026-10-07T13:37:00.000000Z')
                    )
                    ->where('growth.1.status', 'insufficient_history')
                    ->where('growth.2.status', 'insufficient_history')
                )
                ->where('analytics.distributions.0.versions.1.id', $missing->id)
                ->where('analytics.distributions.0.versions.1.status', 'missing')
                ->where('analytics.distributions.0.versions.1.downloads', 40)
                ->has('analytics.distributions.0.removedVersions', 1)
                ->has('analytics.distributions.0.removedVersions.0', fn (Assert $row) => $row
                    ->where('id', $removed->id)
                    ->where('displayName', 'Enderman Grief Control 0.9.0')
                    ->where('versionNumber', '0.9.0')
                    ->where('loaders', ['paper'])
                    ->where('gameVersions', ['1.21.6'])
                    ->where('publishedAt', '2026-08-01T12:00:00.000000Z')
                    ->where('downloads', 10)
                    ->where('capturedAt', '2026-10-06T13:37:00.000000Z')
                    ->where('excludedAt', '2026-10-08T14:00:00.000000Z')
                )
            );
    }

    public function test_authenticated_dashboard_includes_missing_distribution_state(): void
    {
        $user = User::factory()->create();

        $distribution = Distribution::query()->create([
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
                    ->has('growth.ranges', 3)
                    ->has('growth.ranges.0', fn (Assert $range) => $range
                        ->where('key', 'day')
                        ->where('label', '24h')
                        ->where('hours', 24)
                        ->where('downloads', null)
                        ->where('status', 'insufficient_history')
                        ->where('capturedAt', null)
                        ->where('rangeStartedAt', null)
                        ->where('missingDistributionIds', [$distribution->id])
                    )
                    ->where('latestCollection', ['capturedAt' => null, 'windowStartedAt' => null])
                    ->where('coverage', [
                        'distributions' => ['captured' => 0, 'available' => 1],
                        'versions' => ['captured' => 0, 'available' => 0],
                    ])
                    ->has('distributions', 1)
                    ->where('distributions.0.status', 'missing')
                    ->where('distributions.0.downloads', null)
                    ->where('distributions.0.capturedAt', null)
                    ->has('distributions.0.growth', 3)
                    ->has('distributions.0.growth.0', fn (Assert $range) => $range
                        ->where('key', 'day')
                        ->where('label', '24h')
                        ->where('hours', 24)
                        ->where('downloads', null)
                        ->where('status', 'insufficient_history')
                        ->where('capturedAt', null)
                        ->where('baselineAt', null)
                    )
                )
            );
    }
}
