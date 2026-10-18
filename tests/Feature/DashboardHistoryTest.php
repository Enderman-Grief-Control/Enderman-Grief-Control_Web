<?php

namespace Tests\Feature;

use App\Analytics\ReadDashboardAnalytics;
use App\Analytics\ReadDownloadHistory;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Distribution;
use App\Models\DistributionVersion;
use App\Models\DistributionVersionSnapshot;
use App\Models\MetricSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_both_routes_share_the_existing_private_boundary(): void
    {
        foreach (['dashboard', 'dashboard.history'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];
            foreach (['web', 'auth', 'verified'] as $required) {
                $this->assertContains($required, $middleware, $name);
            }

            $this->get(route($name))->assertRedirect(route('login'));
            $this->getJson(route($name))->assertUnauthorized();
        }
    }

    public function test_history_preserves_the_existing_account_verification_rule(): void
    {
        // User currently does not implement MustVerifyEmail; neither route adds it.
        $this->actingAs(User::factory()->unverified()->create());

        $this->get(route('dashboard'))->assertOk();
        $this->getJson(route('dashboard.history'))->assertOk();
    }

    #[DataProvider('dashboardRanges')]
    public function test_initial_dashboard_delivers_the_normalized_range_without_history(?string $range, string $expected): void
    {
        $this->actingAs(User::factory()->create());
        $this->app->bind(ReadDownloadHistory::class, function (): never {
            throw new \LogicException('Initial dashboard must not resolve history.');
        });

        $this->get(route('dashboard', $range === null ? [] : ['historyRange' => $range]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('historyRange', $expected)
                ->has('analytics.latestCollection')
                ->missing('history')
            );
    }

    public static function dashboardRanges(): array
    {
        return [
            'default' => [null, '30d'],
            '7d' => ['7d', '7d'],
            '30d' => ['30d', '30d'],
            '90d' => ['90d', '90d'],
            'all' => ['all', 'all'],
            'unsupported' => ['tomorrow', '30d'],
            'empty string' => ['', '30d'],
        ];
    }

    public function test_dashboard_rejects_non_scalar_ranges(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['historyRange' => ['7d']]))
            ->assertUnprocessable();
    }

    public function test_range_only_partial_reload_does_not_read_analytics_or_history(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(ReadDashboardAnalytics::class)->shouldNotReceive('__invoke');
        $this->app->bind(ReadDownloadHistory::class, function (): never {
            throw new \LogicException('Range-only visit must not resolve history.');
        });
        DB::enableQueryLog();

        $this->get(route('dashboard', ['historyRange' => '90d']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create(route('dashboard'))),
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'historyRange',
        ])->assertOk()
            ->assertJsonPath('props.historyRange', '90d')
            ->assertJsonMissingPath('props.analytics')
            ->assertJsonMissingPath('props.history');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(distributions|metric_snapshots|distribution_versions|distribution_version_snapshots)\b/i', $query['query']);
        }
        Http::assertNothingSent();
    }

    public function test_empty_history_returns_default_range_and_null_bounds(): void
    {
        $distribution = $this->distribution();
        $this->actingAs(User::factory()->create());

        $this->getJson(route('dashboard.history'))
            ->assertOk()
            ->assertJsonPath('scope', 'aggregate')
            ->assertJsonPath('range', '30d')
            ->assertJsonPath('anchorAt', null)
            ->assertJsonPath('domain', ['startedAt' => null, 'endedAt' => null])
            ->assertJsonPath('total.points', [])
            ->assertJsonPath('series.0.id', $distribution->id)
            ->assertJsonPath('series.0.historyStatus', 'never_collected')
            ->assertJsonPath('series.0.points', [])
            ->assertJsonMissingPath('distributionId');
    }

    public function test_aggregate_response_uses_stored_totals_and_the_exact_public_shape(): void
    {
        $distribution = $this->distribution();
        $other = $this->distribution();
        $this->snapshot($distribution, 100, '2026-10-18 12:00:00');
        $this->snapshot($other, 50, '2026-10-18 12:00:00');
        $this->snapshot($this->version($distribution), 999, '2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create());

        $this->getJson(route('dashboard.history', ['range' => 'all']))
            ->assertOk()
            ->assertJsonPath('scope', 'aggregate')
            ->assertJsonPath('range', 'all')
            ->assertJsonPath('anchorAt', '2026-10-18T12:00:00.000000Z')
            ->assertJsonPath('stalenessSeconds', 43200)
            ->assertJsonPath('budget', ['pointTarget' => 20000, 'byteTarget' => 3145728, 'exceeded' => false])
            ->assertJsonCount(2, 'series')
            ->assertJsonPath('series.0.key', 'distribution:'.$distribution->id)
            ->assertJsonPath('series.0.historyStatus', 'single_point')
            ->assertJsonPath('total.points.0.downloads', 150)
            ->assertJsonPath('total.points.0.status', 'complete')
            ->assertExactJsonStructure([
                'scope', 'range', 'anchorAt', 'domain' => ['startedAt', 'endedAt'],
                'stalenessSeconds', 'budget' => ['pointTarget', 'byteTarget', 'exceeded'],
                'series' => ['*' => $this->seriesShape()],
                'total' => [
                    'key', 'sampling' => $this->samplingShape(),
                    'points' => ['*' => [
                        'at', 'downloads', 'knownDownloads', 'status',
                        'contributors' => ['*' => ['distributionId', 'downloads', 'sourceCapturedAt', 'status']],
                    ]],
                ],
            ]);
    }

    public function test_detail_history_keeps_available_identities_and_omits_removed_and_inactive_ones(): void
    {
        $distribution = $this->distribution();
        $this->snapshot($distribution, 100, '2026-10-18 12:00:00');
        $current = $this->version($distribution);
        $missing = $this->version($distribution);
        $never = $this->version($distribution);
        $removed = $this->version($distribution, excluded: true);
        $inactive = $this->distribution(active: false);
        $this->snapshot($current, 0, '2026-10-18 12:00:00');
        $this->snapshot($missing, 10, '2026-10-17 12:00:00');
        $this->snapshot($removed, 20, '2026-10-18 12:00:00');
        $this->snapshot($this->version($inactive), 30, '2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create());

        $response = $this->getJson(route('dashboard.history', ['distribution' => $distribution->id, 'range' => '7d']))
            ->assertOk()
            ->assertJsonPath('scope', 'versions')
            ->assertJsonPath('distributionId', $distribution->id)
            ->assertJsonPath('range', '7d')
            ->assertJsonCount(3, 'series')
            ->assertExactJsonStructure([
                'scope', 'distributionId', 'range', 'anchorAt', 'domain' => ['startedAt', 'endedAt'],
                'stalenessSeconds', 'budget' => ['pointTarget', 'byteTarget', 'exceeded'],
                'series' => ['*' => $this->seriesShape()],
            ]);

        $series = array_column($response->json('series'), null, 'id');
        $this->assertEqualsCanonicalizing([$current->id, $missing->id, $never->id], array_keys($series));
        $this->assertSame('version:'.$current->id, $series[$current->id]['key']);
        $this->assertContains(0, array_column($series[$current->id]['points'], 'downloads'));
        $this->assertSame('single_point', $series[$missing->id]['historyStatus']);
        $this->assertSame('never_collected', $series[$never->id]['historyStatus']);

        $this->getJson(route('dashboard.history'))->assertJsonCount(1, 'series');
        $this->getJson(route('dashboard.history', ['distribution' => $inactive->id]))->assertNotFound();
        $this->getJson(route('dashboard.history', ['distribution' => 999999]))->assertNotFound();
    }

    public function test_remove_and_restore_update_detail_availability_at_the_same_anchor(): void
    {
        $distribution = $this->distribution();
        $version = $this->version($distribution);
        $this->snapshot($distribution, 100, '2026-10-18 12:00:00');
        $this->snapshot($version, 10, '2026-10-17 12:00:00');
        $this->actingAs(User::factory()->create());
        $historyUrl = route('dashboard.history', [
            'distribution' => $distribution->id, 'anchor' => '2026-10-18T12:00:00.000000Z',
        ]);

        $this->getJson($historyUrl)->assertOk()->assertJsonPath('series.0.id', $version->id);
        $this->from(route('dashboard', ['historyRange' => '7d']))
            ->post(route('dashboard.versions.exclusion.store', $version))
            ->assertRedirect(route('dashboard', ['historyRange' => '7d']))
            ->assertSessionHasNoErrors();
        $this->getJson($historyUrl)->assertOk()->assertJsonPath('series', []);

        $this->delete(route('dashboard.versions.exclusion.destroy', $version))->assertSessionHasNoErrors();
        $this->getJson($historyUrl)->assertOk()->assertJsonPath('series.0.id', $version->id);
        $this->assertDatabaseCount('distribution_version_snapshots', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidHistoryParameters')]
    public function test_malformed_history_parameters_return_json_422_without_an_accept_header(array $query, string $field): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.history', $query))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors($field);
    }

    public static function invalidHistoryParameters(): array
    {
        return [
            'unsupported range' => [['range' => 'tomorrow'], 'range'],
            'empty range' => [['range' => ''], 'range'],
            'array range' => [['range' => ['7d']], 'range'],
            'empty distribution' => [['distribution' => ''], 'distribution'],
            'array distribution' => [['distribution' => ['1']], 'distribution'],
            'non-numeric distribution' => [['distribution' => 'modrinth'], 'distribution'],
            'fractional distribution' => [['distribution' => '1.5'], 'distribution'],
            'zero distribution' => [['distribution' => '0'], 'distribution'],
            'negative distribution' => [['distribution' => '-1'], 'distribution'],
            'overflowing distribution' => [['distribution' => '999999999999999999999999'], 'distribution'],
            'empty anchor' => [['anchor' => ''], 'anchor'],
            'array anchor' => [['anchor' => ['2026-10-18T12:00:00Z']], 'anchor'],
            'relative anchor' => [['anchor' => 'tomorrow'], 'anchor'],
            'local anchor' => [['anchor' => '2026-10-18 12:00:00'], 'anchor'],
            'offset anchor' => [['anchor' => '2026-10-18T12:00:00+01:00'], 'anchor'],
            'invalid date' => [['anchor' => '2026-02-30T12:00:00Z'], 'anchor'],
            'overflowing hour' => [['anchor' => '2026-10-18T24:00:00Z'], 'anchor'],
            'overflowing second' => [['anchor' => '2026-10-18T12:00:60Z'], 'anchor'],
            'excess precision' => [['anchor' => '2026-10-18T12:00:00.0000001Z'], 'anchor'],
        ];
    }

    public function test_pinned_anchor_survives_a_later_collection_and_excludes_future_detail(): void
    {
        $distribution = $this->distribution();
        $version = $this->version($distribution);
        $this->snapshot($distribution, 100, '2026-10-17 12:00:00');
        $this->snapshot($distribution, 200, '2026-10-18 12:00:00');
        $this->snapshot($version, 10, '2026-10-17 12:00:00');
        $this->snapshot($version, 99, '2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create());

        foreach (['2026-10-17T12:00:00Z', '2026-10-17T12:00:00.000000Z'] as $anchor) {
            foreach ([[], ['distribution' => $distribution->id]] as $scope) {
                $response = $this->getJson(route('dashboard.history', ['anchor' => $anchor, 'range' => 'all', ...$scope]))
                    ->assertOk()
                    ->assertJsonPath('anchorAt', '2026-10-17T12:00:00.000000Z')
                    ->assertJsonPath('domain.endedAt', '2026-10-17T12:00:00.000000Z');
                $this->assertSame([$scope === [] ? 100 : 10], array_column($response->json('series.0.points'), 'downloads'));
            }
        }

        $this->getJson(route('dashboard.history'))->assertJsonPath('anchorAt', '2026-10-18T12:00:00.000000Z');
    }

    public function test_well_formed_anchors_must_be_active_aggregate_captures(): void
    {
        $distribution = $this->distribution();
        $this->snapshot($distribution, 100, '2026-10-18 12:00:00');
        $this->snapshot($this->version($distribution), 10, '2026-10-18 12:01:00');
        $this->snapshot($this->distribution(active: false), 999, '2026-10-18 12:02:00');
        $this->actingAs(User::factory()->create());

        foreach (['2030-01-01T00:00:00Z', '2026-10-18T11:59:00Z', '2026-10-18T12:01:00Z', '2026-10-18T12:02:00Z', '2026-10-18T12:00:00.000001Z'] as $anchor) {
            $this->get(route('dashboard.history', ['anchor' => $anchor]))
                ->assertUnprocessable()
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonValidationErrors('anchor');
        }
    }

    public function test_history_requests_use_only_selects_and_never_call_providers(): void
    {
        $distribution = $this->distribution();
        $this->snapshot($distribution, 100, '2026-10-18 12:00:00');
        $this->snapshot($this->version($distribution), 10, '2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create());
        DB::enableQueryLog();

        foreach (['7d', '30d', '90d', 'all'] as $range) {
            foreach ([[], ['distribution' => $distribution->id]] as $scope) {
                DB::flushQueryLog();
                $this->getJson(route('dashboard.history', ['range' => $range, 'anchor' => '2026-10-18T12:00:00.000000Z', ...$scope]))
                    ->assertOk()->assertJsonPath('range', $range);
                $queries = DB::getQueryLog();
                $this->assertLessThanOrEqual(7, count($queries));
                foreach ($queries as $query) {
                    $this->assertMatchesRegularExpression('/^\s*(select|with)\b/i', $query['query']);
                }
            }
        }
        DB::disableQueryLog();
        Http::assertNothingSent();
    }

    private function seriesShape(): array
    {
        return [
            'key', 'id', 'distributionId', 'label', 'historyStatus', 'observationCount',
            'sampling' => $this->samplingShape(),
            'points' => ['*' => ['at', 'downloads', 'sourceCapturedAt', 'kind', 'gapReason']],
        ];
    }

    private function samplingShape(): array
    {
        return ['mode', 'bucketSeconds', 'originalObservationCount', 'returnedObservationCount', 'returnedPointCount'];
    }

    private function distribution(bool $active = true): Distribution
    {
        return Distribution::query()->create([
            'provider' => 'modrinth', 'name' => 'Modrinth', 'loader' => 'combined',
            'project_identifier' => fake()->unique()->uuid(), 'listing_url' => 'https://example.com', 'active' => $active,
        ]);
    }

    private function version(Distribution $distribution, bool $excluded = false): DistributionVersion
    {
        return DistributionVersion::query()->create([
            'distribution_id' => $distribution->id, 'provider_version_identifier' => fake()->unique()->uuid(),
            'display_name' => 'Same name', 'loaders' => [], 'game_versions' => [],
            'excluded_at' => $excluded ? '2026-10-18 00:00:00' : null,
        ]);
    }

    private function snapshot(Distribution|DistributionVersion $identity, int $downloads, string $at): void
    {
        $attributes = ['downloads' => $downloads, 'captured_at' => CarbonImmutable::parse($at, 'UTC')];
        if ($identity instanceof Distribution) {
            MetricSnapshot::query()->create(['distribution_id' => $identity->id, ...$attributes]);
        } else {
            DistributionVersionSnapshot::query()->create(['distribution_version_id' => $identity->id, ...$attributes]);
        }
    }
}
