<?php

namespace Tests\Feature\Analytics;

use App\Analytics\ReadDownloadHistory;
use App\Models\Distribution;
use App\Models\DistributionVersion;
use App\Models\DistributionVersionSnapshot;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class ReadDownloadHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_history_keeps_identities_and_null_domains(): void
    {
        $distribution = $this->distribution();
        $version = $this->version($distribution);
        $this->distribution(active: false);

        foreach (['7d', '30d', '90d', 'all'] as $range) {
            $aggregate = (new ReadDownloadHistory)($range);
            $detail = (new ReadDownloadHistory)($range, $distribution->id);
            $this->assertNull($aggregate['anchorAt']);
            $this->assertSame(['startedAt' => null, 'endedAt' => null], $aggregate['domain']);
            $this->assertSame($aggregate['domain'], $detail['domain']);
            $this->assertSame([$distribution->id], array_column($aggregate['series'], 'id'));
            $this->assertSame([$version->id], array_column($detail['series'], 'id'));
            $this->assertSame('never_collected', $detail['series'][0]['historyStatus']);
            $this->assertSame([], $detail['series'][0]['points']);
            $this->assertSame([], $aggregate['total']['points']);
        }
    }

    public function test_asynchronous_totals_preserve_zero_unknown_sources_expiry_and_corrections(): void
    {
        $m = $this->distribution();
        $f = $this->distribution('curseforge', 'fabric');
        $this->snapshot($m, 100, '2026-10-17 00:00:00');
        $this->snapshot($f, 0, '2026-10-17 00:00:02');
        $this->snapshot($m, 110, '2026-10-17 06:00:00');
        $this->snapshot($m, 115, '2026-10-17 12:00:00');
        $this->snapshot($f, 25, '2026-10-17 13:00:00');
        $this->snapshot($m, 108, '2026-10-17 18:00:00');

        $history = (new ReadDownloadHistory)('all');
        $points = array_column($history['total']['points'], null, 'at');
        $initial = $points[$this->iso('2026-10-17 00:00:00')];
        $this->assertNull($initial['downloads']);
        $this->assertSame(100, $initial['knownDownloads']);
        $this->assertSame('partial', $initial['status']);
        $this->assertSame(['observed', 'unknown'], array_column($initial['contributors'], 'status'));
        $zero = $points[$this->iso('2026-10-17 00:00:02')];
        $this->assertSame(100, $zero['downloads']);
        $this->assertSame([100, 0], array_column($zero['contributors'], 'downloads'));
        $this->assertSame(['carried', 'observed'], array_column($zero['contributors'], 'status'));
        $this->assertSame(115, $points['2026-10-17T12:00:01.999000Z']['downloads']);
        $expired = $points[$this->iso('2026-10-17 12:00:02')];
        $this->assertNull($expired['downloads']);
        $this->assertSame(115, $expired['knownDownloads']);
        $this->assertSame('stale', $expired['contributors'][1]['status']);
        $this->assertSame($this->iso('2026-10-17 00:00:02'), $expired['contributors'][1]['sourceCapturedAt']);
        $this->assertSame(140, $points[$this->iso('2026-10-17 13:00:00')]['downloads']);
        $this->assertSame(133, $points[$this->iso('2026-10-17 18:00:00')]['downloads']);
    }

    public function test_finite_ranges_include_both_boundaries_and_only_the_newest_tied_observation(): void
    {
        foreach ([7, 30, 90] as $days) {
            $d = $this->distribution();
            $v = $this->version($d);
            $anchor = CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC');
            $start = $anchor->subDays($days);
            $this->snapshot($d, 5, $start->subSecond()->toDateTimeString());
            $this->snapshot($d, 10, $start->toDateTimeString());
            $this->snapshot($d, 11, $start->toDateTimeString());
            $this->snapshot($d, 20, $anchor->toDateTimeString());
            $this->snapshot($v, 1, $start->toDateTimeString());
            $this->snapshot($v, 2, $start->toDateTimeString());
            $this->snapshot($v, 3, $anchor->toDateTimeString());
            $this->snapshot($v, 999, $anchor->addSeconds(2)->toDateTimeString());

            foreach ([null, $d->id] as $scope) {
                $history = (new ReadDownloadHistory)("{$days}d", $scope, $anchor);
                $series = collect($history['series'])->firstWhere('id', $scope === null ? $d->id : $v->id);
                $observed = array_values(array_filter($series['points'], fn ($p) => $p['kind'] === 'observed'));
                $this->assertSame($start->toJSON(), $history['domain']['startedAt']);
                $this->assertSame(2, $series['observationCount']);
                $this->assertSame([$scope === null ? 11 : 2, $scope === null ? 20 : 3], array_column($observed, 'downloads'));
                $this->assertSame([$start->toJSON(), $anchor->toJSON()], array_column($observed, 'at'));
            }
        }
    }

    public function test_pre_range_sources_are_carried_or_expired_without_becoming_observations(): void
    {
        $d = $this->distribution();
        $fresh = $this->version($d);
        $stale = $this->version($d);
        $empty = $this->version($d);
        $this->snapshot($d, 100, '2026-10-18 12:00:00');
        $this->snapshot($fresh, 9, '2026-10-11 11:00:00');
        $this->snapshot($fresh, 10, '2026-10-11 11:00:00');
        $this->snapshot($stale, 20, '2026-10-10 23:00:00');

        $history = (new ReadDownloadHistory)('7d', $d->id);
        $series = array_column($history['series'], null, 'id');
        $this->assertSame('outside_range', $series[$fresh->id]['historyStatus']);
        $this->assertSame(0, $series[$fresh->id]['observationCount']);
        $this->assertSame([
            'at' => $this->iso('2026-10-11 12:00:00'), 'downloads' => 10,
            'sourceCapturedAt' => $this->iso('2026-10-11 11:00:00'), 'kind' => 'carried', 'gapReason' => null,
        ], $series[$fresh->id]['points'][0]);
        $this->assertSame('expired', $series[$stale->id]['points'][0]['gapReason']);
        $this->assertNull($series[$stale->id]['points'][0]['downloads']);
        $this->assertSame('outside_range', $series[$stale->id]['historyStatus']);
        $this->assertSame('never_collected', $series[$empty->id]['historyStatus']);
        $this->assertSame([], $series[$empty->id]['points']);
    }

    public function test_all_has_one_global_domain_and_omits_inactive_and_removed_history(): void
    {
        $d = $this->distribution();
        $other = $this->distribution('curseforge', 'fabric');
        $inactive = $this->distribution(active: false);
        $removed = $this->version($d, excluded: true);
        $old = $this->version($other);
        $this->snapshot($inactive, 1000, '2020-01-01 00:00:00');
        $this->snapshot($inactive, 9999, '2030-01-01 00:00:00');
        $this->snapshot($removed, 1000, '2021-01-01 00:00:00');
        $this->snapshot($old, 1, '2026-10-01 00:00:00');
        $this->snapshot($d, 100, '2026-10-18 12:00:00');
        $aggregate = (new ReadDownloadHistory)('all');
        $detail = (new ReadDownloadHistory)('all', $d->id);
        $this->assertSame($aggregate['domain'], $detail['domain']);
        $this->assertSame($this->iso('2026-10-01 00:00:00'), $detail['domain']['startedAt']);
        $this->assertSame([], $detail['series']);
        $this->assertSame([$d->id, $other->id], array_column($aggregate['series'], 'id'));
        $this->assertSame($this->iso('2026-10-18 12:00:00'), $detail['anchorAt']);
    }

    public function test_new_versions_start_at_their_first_capture_and_duplicate_labels_keep_identity_order(): void
    {
        $d = $this->distribution();
        $old = $this->version($d, published: '2026-10-01 00:00:00');
        $new = $this->version($d, published: '2026-10-17 00:00:00');
        $unknown = $this->version($d);
        $this->snapshot($d, 10, '2026-10-18 12:00:00');
        $this->snapshot($new, 0, '2026-10-18 03:00:00');
        $this->snapshot($old, 5, '2026-10-17 12:00:00');
        $this->snapshot($unknown, 999, '2026-10-18 12:00:02');
        $history = (new ReadDownloadHistory)('7d', $d->id);
        $this->assertSame([$new->id, $old->id, $unknown->id], array_column($history['series'], 'id'));
        $this->assertSame(['same name', 'same name', 'same name'], array_column($history['series'], 'label'));
        $this->assertSame('version:'.$new->id, $history['series'][0]['key']);
        $this->assertSame('single_point', $history['series'][0]['historyStatus']);
        $this->assertSame($this->iso('2026-10-18 03:00:00'), $history['series'][0]['points'][0]['at']);
        $this->assertSame(0, $history['series'][0]['points'][0]['downloads']);
        $this->assertSame('never_collected', $history['series'][2]['historyStatus']);
        $this->assertSame([], $history['series'][2]['points']);
    }

    public function test_capture_at_exact_expiry_renews_freshness_without_a_gap(): void
    {
        $d = $this->distribution();
        $v = $this->version($d);
        foreach ([$d, $v] as $identity) {
            $this->snapshot($identity, 10, '2026-10-17 00:00:00');
            $this->snapshot($identity, 10, '2026-10-17 12:00:00');
            $this->snapshot($identity, 9, '2026-10-18 00:00:00');
        }
        foreach ([null, $d->id] as $scope) {
            $history = (new ReadDownloadHistory)('all', $scope);
            $this->assertSame(['observed', 'observed', 'observed'], array_column($history['series'][0]['points'], 'kind'));
            $this->assertSame([10, 10, 9], array_column($history['series'][0]['points'], 'downloads'));
        }
    }

    public function test_an_old_anchor_remains_pinned_after_later_collections(): void
    {
        $d = $this->distribution();
        $v = $this->version($d);
        $this->snapshot($d, 10, '2026-10-17 12:00:00');
        $this->snapshot($v, 5, '2026-10-17 12:00:00');
        $this->snapshot($d, 20, '2026-10-18 12:00:00');
        $this->snapshot($v, 9, '2026-10-18 12:00:00');
        foreach ([null, $d->id] as $scope) {
            $history = (new ReadDownloadHistory)('all', $scope, CarbonImmutable::parse('2026-10-17T05:00:00-07:00'));
            $this->assertSame($this->iso('2026-10-17 12:00:00'), $history['anchorAt']);
            $this->assertSame($history['anchorAt'], $history['domain']['startedAt']);
            $this->assertSame(1, $history['series'][0]['observationCount']);
            $this->assertSame($scope === null ? 10 : 5, $history['series'][0]['points'][0]['downloads']);
        }
    }

    public function test_sampling_preserves_full_neighbor_gaps_and_both_sides_of_corrections(): void
    {
        $d = $this->distribution();
        $v = $this->version($d);
        $start = CarbonImmutable::parse('2025-01-01 00:00:00', 'UTC');
        $rows = [];
        for ($i = 0; $i < 1800; $i++) {
            // One actual 24h gap. Ordinary samples may be days apart, but all
            // other full-stream captures remain six hours apart.
            if ($i > 900 && $i < 904) {
                continue;
            }
            $rows[] = ['distribution_version_id' => $v->id, 'downloads' => $i === 777 ? 700 : $i, 'captured_at' => $start->addHours($i * 6)->toDateTimeString()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('distribution_version_snapshots')->insert($chunk);
        }
        $anchor = $start->addHours(1799 * 6);
        $this->snapshot($d, 9000, $anchor->toDateTimeString());
        $history = (new ReadDownloadHistory)('all', $d->id);
        $series = $history['series'][0];
        $points = array_column($series['points'], null, 'at');
        $this->assertSame('min_max', $series['sampling']['mode']);
        $this->assertSame(1797, $series['observationCount']);
        $this->assertSame(1797, $series['sampling']['originalObservationCount']);
        $this->assertLessThan(1500, $series['sampling']['returnedObservationCount']);
        $this->assertSame(776, $points[$start->addHours(776 * 6)->toJSON()]['downloads']);
        $this->assertSame(700, $points[$start->addHours(777 * 6)->toJSON()]['downloads']);
        $this->assertSame(900, $points[$start->addHours(900 * 6)->toJSON()]['downloads']);
        $this->assertSame(904, $points[$start->addHours(904 * 6)->toJSON()]['downloads']);
        $gaps = array_values(array_filter($series['points'], fn ($p) => $p['kind'] === 'gap'));
        $this->assertCount(1, $gaps);
        $this->assertSame($start->addHours(902 * 6)->toJSON(), $gaps[0]['at']);
        $this->assertSame($start->addHours(900 * 6)->toJSON(), $gaps[0]['sourceCapturedAt']);
        $this->assertArrayHasKey($start->addHours(902 * 6)->subMillisecond()->toJSON(), $points);
        $this->assertFalse($history['budget']['exceeded']);
        $this->assertLessThan(3145728, strlen(json_encode($history, JSON_THROW_ON_ERROR)));
    }

    public function test_aggregate_sampling_aligns_full_sources_and_keeps_partial_coverage_transitions(): void
    {
        $m = $this->distribution();
        $f = $this->distribution('curseforge', 'fabric');
        $start = CarbonImmutable::parse('2025-01-01 00:00:00', 'UTC');
        $rows = [];
        for ($i = 0; $i < 1600; $i++) {
            $rows[] = ['distribution_id' => $m->id, 'downloads' => $i === 777 ? 700 : $i, 'captured_at' => $start->addHours($i * 6)->toDateTimeString()];
            if ($i < 901 || $i >= 904) {
                $rows[] = ['distribution_id' => $f->id, 'downloads' => 100, 'captured_at' => $start->addHours($i * 6)->addSeconds(2)->toDateTimeString()];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('metric_snapshots')->insert($chunk);
        }
        $history = (new ReadDownloadHistory)('all');
        $points = array_column($history['total']['points'], null, 'at');
        $this->assertSame('min_max', $history['total']['sampling']['mode']);
        $correctionAt = $start->addHours(777 * 6)->toJSON();
        $this->assertSame(800, $points[$correctionAt]['downloads']);
        $this->assertSame($start->addHours(776 * 6)->addSeconds(2)->toJSON(), $points[$correctionAt]['contributors'][1]['sourceCapturedAt']);
        $expiredAt = $start->addHours(902 * 6)->addSeconds(2);
        $this->assertNull($points[$expiredAt->toJSON()]['downloads']);
        $this->assertSame('partial', $points[$expiredAt->toJSON()]['status']);
        $this->assertSame('complete', $points[$expiredAt->subMillisecond()->toJSON()]['status']);
        $this->assertSame('complete', $points[$start->addHours(904 * 6)->addSeconds(2)->toJSON()]['status']);
        $this->assertLessThan(1500, $history['total']['sampling']['returnedObservationCount']);
    }

    public function test_scope_budget_can_trigger_reduction_with_no_per_series_overflow(): void
    {
        $d = $this->distribution();
        $start = CarbonImmutable::parse('2026-01-01', 'UTC');
        $versions = [];
        $rows = [];
        for ($v = 0; $v < 21; $v++) {
            $version = $this->version($d);
            $versions[] = $version->id;
            for ($i = 0; $i < 1000; $i++) {
                $rows[] = ['distribution_version_id' => $version->id, 'downloads' => $i, 'captured_at' => $start->addHours($i * 6)->toDateTimeString()];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('distribution_version_snapshots')->insert($chunk);
        }
        $this->snapshot($d, 100, $start->addHours(999 * 6)->toDateTimeString());
        $history = (new ReadDownloadHistory)('all', $d->id);
        $this->assertEqualsCanonicalizing($versions, array_column($history['series'], 'id'));
        $this->assertSame(['min_max'], array_values(array_unique(array_column(array_column($history['series'], 'sampling'), 'mode'))));
        $this->assertFalse($history['budget']['exceeded']);
        $this->assertLessThan(20000, array_sum(array_column(array_column($history['series'], 'sampling'), 'returnedPointCount')));
    }

    public function test_partial_total_sampling_keeps_observations_and_known_subtotal_corrections(): void
    {
        $d = $this->distribution();
        $this->distribution('curseforge', 'fabric'); // Never captured: total stays partial.
        $start = CarbonImmutable::parse('2025-01-01', 'UTC');
        $rows = [];
        for ($i = 0; $i < 1600; $i++) {
            $rows[] = ['distribution_id' => $d->id, 'downloads' => $i === 777 ? 700 : $i, 'captured_at' => $start->addHours($i * 6)->toDateTimeString()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('metric_snapshots')->insert($chunk);
        }
        $history = (new ReadDownloadHistory)('all');
        $points = array_column($history['total']['points'], null, 'at');
        $this->assertSame('min_max', $history['total']['sampling']['mode']);
        $this->assertSame(1600, $history['total']['sampling']['originalObservationCount']);
        $this->assertGreaterThan(120, $history['total']['sampling']['returnedObservationCount']);
        $this->assertLessThan(1500, $history['total']['sampling']['returnedObservationCount']);
        $this->assertSame(776, $points[$start->addHours(776 * 6)->toJSON()]['knownDownloads']);
        $this->assertSame(700, $points[$start->addHours(777 * 6)->toJSON()]['knownDownloads']);
        foreach ($points as $point) {
            $this->assertNull($point['downloads']);
            $this->assertSame('partial', $point['status']);
        }
    }

    public function test_exact_point_limit_counts_coincident_range_and_expiry_boundaries_once(): void
    {
        $d = $this->distribution();
        $v = $this->version($d);
        $anchor = CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC');
        $start = $anchor->subDays(90);
        // This boundary source expires exactly at S and produces one gap at S,
        // followed by 1,499 real captures. Exactly 1,500 points is exact mode.
        $this->snapshot($v, 0, $start->subHours(12)->toDateTimeString());
        $rows = [];
        for ($i = 0; $i < 1499; $i++) {
            $rows[] = ['distribution_version_id' => $v->id, 'downloads' => $i, 'captured_at' => $anchor->subHours(1498 - $i)->toDateTimeString()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('distribution_version_snapshots')->insert($chunk);
        }
        $this->snapshot($d, 100, $anchor->toDateTimeString());
        $series = (new ReadDownloadHistory)('90d', $d->id)['series'][0];
        $this->assertSame('exact', $series['sampling']['mode']);
        $this->assertSame(1500, $series['sampling']['returnedPointCount']);
        $this->assertSame('expired', $series['points'][0]['gapReason']);
        $this->assertSame($start->toJSON(), $series['points'][0]['at']);
    }

    public function test_protected_points_exceed_budget_explicitly_instead_of_being_truncated(): void
    {
        $d = $this->distribution();
        $v = $this->version($d);
        $start = CarbonImmutable::parse('2026-01-01', 'UTC');
        $rows = [];
        for ($i = 0; $i < 20002; $i++) {
            $rows[] = ['distribution_version_id' => $v->id, 'downloads' => $i % 2, 'captured_at' => $start->addMinutes($i)->toDateTimeString()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('distribution_version_snapshots')->insert($chunk);
        }
        $this->snapshot($d, 100, $start->addMinutes(20001)->toDateTimeString());
        $history = (new ReadDownloadHistory)('all', $d->id);
        $this->assertTrue($history['budget']['exceeded']);
        $this->assertSame(20002, $history['series'][0]['sampling']['returnedObservationCount']);
        $this->assertSame('min_max', $history['series'][0]['sampling']['mode']);
    }

    public function test_history_reads_are_bounded_selects_and_make_no_provider_requests(): void
    {
        $d = $this->distribution();
        $this->snapshot($d, 10, '2026-10-18 12:00:00');
        for ($i = 0; $i < 10; $i++) {
            $this->snapshot($this->version($d), $i, '2026-10-18 11:00:00');
        }
        Http::preventStrayRequests();
        foreach (['7d', '30d', '90d', 'all'] as $range) {
            foreach ([null, $d->id] as $scope) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                (new ReadDownloadHistory)($range, $scope);
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $this->assertLessThanOrEqual(7, count($queries));
                foreach ($queries as $query) {
                    $this->assertMatchesRegularExpression('/^\s*(select|with)\b/i', $query['query']);
                }
            }
        }
        Http::assertNothingSent();
    }

    public function test_invalid_ranges_are_rejected_by_the_read_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReadDownloadHistory)('tomorrow');
    }

    public function test_inactive_distributions_are_not_readable(): void
    {
        $d = $this->distribution(active: false);
        $this->expectException(ModelNotFoundException::class);
        (new ReadDownloadHistory)('30d', $d->id);
    }

    public function test_nonexistent_and_future_anchors_are_rejected(): void
    {
        $d = $this->distribution();
        $this->snapshot($d, 10, '2026-10-18 12:00:00');
        foreach (['2026-10-18 11:00:00', '2030-01-01 00:00:00'] as $time) {
            try {
                (new ReadDownloadHistory)(anchorAt: CarbonImmutable::parse($time, 'UTC'));
                $this->fail('An anchor without an active aggregate capture was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('anchor', $exception->getMessage());
            }
        }
    }

    private function distribution(string $provider = 'modrinth', string $loader = 'combined', bool $active = true): Distribution
    {
        return Distribution::query()->create([
            'provider' => $provider, 'name' => $provider.' '.$loader, 'loader' => $loader,
            'project_identifier' => fake()->unique()->uuid(), 'listing_url' => 'https://example.com', 'active' => $active,
        ]);
    }

    private function version(Distribution $distribution, bool $excluded = false, ?string $published = null): DistributionVersion
    {
        return DistributionVersion::query()->create([
            'distribution_id' => $distribution->id, 'provider_version_identifier' => fake()->unique()->uuid(),
            'display_name' => 'same name', 'loaders' => [], 'game_versions' => [], 'published_at' => $published,
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

    private function iso(string $time): string
    {
        return CarbonImmutable::parse($time, 'UTC')->toJSON();
    }
}
