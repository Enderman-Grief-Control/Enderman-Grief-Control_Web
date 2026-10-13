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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DistributionVersionExclusionTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchorAt;

    protected function setUp(): void
    {
        parent::setUp();

        // Remove/restore only maintain local analytics data, never provider APIs.
        Http::preventStrayRequests();

        $this->anchorAt = CarbonImmutable::parse('2026-10-08 13:37:00', 'UTC');
    }

    public function test_routes_use_web_auth_and_verified_middleware(): void
    {
        foreach (['dashboard.versions.exclusion.store', 'dashboard.versions.exclusion.destroy'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            $this->assertContains('web', $middleware, $name);
            $this->assertContains('auth', $middleware, $name);
            $this->assertContains('verified', $middleware, $name);
        }
    }

    public function test_guests_are_redirected_for_both_actions(): void
    {
        $version = $this->createVersion($this->createDistribution());
        $this->captureVersion($version, $this->anchorAt->subHours(8));

        $this->post(route('dashboard.versions.exclusion.store', $version))->assertRedirect(route('login'));
        $this->delete(route('dashboard.versions.exclusion.destroy', $version))->assertRedirect(route('login'));

        $this->assertNull($version->refresh()->excluded_at);
    }

    public function test_missing_version_can_be_removed_and_history_is_kept(): void
    {
        $distribution = $this->createDistribution();
        $this->captureDistribution($distribution, $this->anchorAt);
        $version = $this->createVersion($distribution);
        $this->captureVersion($version, $this->anchorAt->subHours(8));
        $this->captureVersion($version, $this->anchorAt->subHours(1));

        $removedAt = CarbonImmutable::parse('2026-10-08 14:00:00', 'UTC');
        $this->travelTo($removedAt);

        $this->actingAs(User::factory()->create());

        $this->from(route('dashboard'))
            ->post(route('dashboard.versions.exclusion.store', $version))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $version->refresh();

        $this->assertNotNull($version->excluded_at);
        $this->assertTrue($removedAt->equalTo($version->excluded_at));
        $this->assertSame(2, $version->snapshots()->count());
        $this->assertDatabaseCount('distribution_versions', 1);
    }

    public function test_version_without_any_collection_is_missing_and_can_be_removed(): void
    {
        $version = $this->createVersion($this->createDistribution());

        $this->actingAs(User::factory()->create());

        $this->post(route('dashboard.versions.exclusion.store', $version))->assertSessionHasNoErrors();

        $this->assertNotNull($version->refresh()->excluded_at);
    }

    public function test_current_version_cannot_be_removed(): void
    {
        $distribution = $this->createDistribution();
        $this->captureDistribution($distribution, $this->anchorAt);
        $version = $this->createVersion($distribution);
        // Exactly at the window start is still inside the latest collection.
        $this->captureVersion($version, $this->anchorAt->subMinutes(30));

        $this->actingAs(User::factory()->create());

        $this->from(route('dashboard'))
            ->post(route('dashboard.versions.exclusion.store', $version))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('version');

        $this->assertNull($version->refresh()->excluded_at);
    }

    public function test_anchor_ignores_inactive_distributions(): void
    {
        $distribution = $this->createDistribution();
        $this->captureDistribution($distribution, $this->anchorAt);
        $inactive = $this->createDistribution(['project_identifier' => 'inactive', 'active' => false]);
        // A newer capture on an inactive distribution must not move the anchor.
        $this->captureDistribution($inactive, $this->anchorAt->addHours(6));
        $version = $this->createVersion($distribution);
        $this->captureVersion($version, $this->anchorAt);

        $this->actingAs(User::factory()->create());

        $this->post(route('dashboard.versions.exclusion.store', $version))->assertSessionHasErrors('version');

        $this->assertNull($version->refresh()->excluded_at);
    }

    public function test_already_removed_version_cannot_be_removed_again(): void
    {
        $excludedAt = CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC');
        $version = $this->createVersion($this->createDistribution(), ['excluded_at' => $excludedAt]);

        $this->actingAs(User::factory()->create());

        $this->post(route('dashboard.versions.exclusion.store', $version))->assertSessionHasErrors('version');

        $this->assertTrue($excludedAt->equalTo($version->refresh()->excluded_at));
    }

    public function test_removed_version_can_be_restored_and_history_is_kept(): void
    {
        $version = $this->createVersion($this->createDistribution(), [
            'excluded_at' => CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'),
        ]);
        $this->captureVersion($version, $this->anchorAt->subHours(8));

        $this->actingAs(User::factory()->create());

        $this->from(route('dashboard'))
            ->delete(route('dashboard.versions.exclusion.destroy', $version))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertNull($version->refresh()->excluded_at);
        $this->assertSame(1, $version->snapshots()->count());
    }

    public function test_restoring_an_available_version_is_a_no_op(): void
    {
        $version = $this->createVersion($this->createDistribution());
        $updatedAt = $version->updated_at;

        $this->actingAs(User::factory()->create());

        $this->from(route('dashboard'))
            ->delete(route('dashboard.versions.exclusion.destroy', $version))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $version->refresh();

        $this->assertNull($version->excluded_at);
        $this->assertTrue($updatedAt?->equalTo($version->updated_at) ?? false);
    }

    public function test_versions_of_inactive_distributions_return_not_found(): void
    {
        $inactive = $this->createDistribution(['active' => false]);
        $available = $this->createVersion($inactive);
        $excludedAt = CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC');
        $removed = $this->createVersion($inactive, [
            'provider_version_identifier' => 'removed-version',
            'excluded_at' => $excludedAt,
        ]);

        $this->actingAs(User::factory()->create());

        $this->post(route('dashboard.versions.exclusion.store', $available))->assertNotFound();
        $this->delete(route('dashboard.versions.exclusion.destroy', $removed))->assertNotFound();

        $this->assertNull($available->refresh()->excluded_at);
        $this->assertTrue($excludedAt->equalTo($removed->refresh()->excluded_at));
    }

    public function test_unknown_version_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('dashboard.versions.exclusion.store', 999))->assertNotFound();
        $this->delete(route('dashboard.versions.exclusion.destroy', 999))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createDistribution(array $attributes = []): Distribution
    {
        return Distribution::query()->create([
            'provider' => 'modrinth',
            'name' => 'Modrinth',
            'loader' => 'combined',
            'project_identifier' => 'modrinth-combined',
            'listing_url' => 'https://modrinth.com/plugin/enderman-grief-control',
            'active' => true,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createVersion(Distribution $distribution, array $attributes = []): DistributionVersion
    {
        return DistributionVersion::query()->create([
            'distribution_id' => $distribution->id,
            'provider_version_identifier' => 'version-1',
            'version_number' => '1.0.0',
            'display_name' => 'Enderman Grief Control 1.0.0',
            'loaders' => ['paper'],
            'game_versions' => ['1.21.8'],
            'published_at' => CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC'),
            ...$attributes,
        ]);
    }

    private function captureDistribution(Distribution $distribution, CarbonImmutable $capturedAt): void
    {
        MetricSnapshot::query()->create([
            'distribution_id' => $distribution->id,
            'downloads' => 1000,
            'captured_at' => $capturedAt,
        ]);
    }

    private function captureVersion(DistributionVersion $version, CarbonImmutable $capturedAt): void
    {
        DistributionVersionSnapshot::query()->create([
            'distribution_version_id' => $version->id,
            'downloads' => 100,
            'captured_at' => $capturedAt,
        ]);
    }
}
