<?php

namespace Tests\Unit\Analytics;

use App\Analytics\ProjectMetrics;
use App\Analytics\Providers\ModrinthProvider;
use App\Models\Distribution;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

class ModrinthProviderTest extends TestCase
{
    public function test_it_maps_modrinth_project_metrics_to_normalized_metrics(): void
    {
        Http::fake([
            'https://api.modrinth.test/v2/project/6jCDxmNc' => Http::response([
                'id' => '6jCDxmNc',
                'slug' => 'enderman-grief-control',
                'downloads' => 697,
                'followers' => 1,
            ]),
        ]);

        config([
            'services.modrinth.base_url' => 'https://api.modrinth.test/v2',
            'services.modrinth.user_agent' => 'EndermanGriefControlWeb/Test',
        ]);

        $metrics = app(ModrinthProvider::class)->getProjectMetrics($this->distribution());

        $this->assertInstanceOf(ProjectMetrics::class, $metrics);
        $this->assertSame(697, $metrics->downloads);
        $this->assertSame(1, $metrics->followers);
        $this->assertNull($metrics->likes);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.modrinth.test/v2/project/6jCDxmNc'
            && $request->hasHeader('User-Agent', 'EndermanGriefControlWeb/Test')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_it_fails_on_unsuccessful_modrinth_responses(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'not found'], 404),
        ]);

        $this->expectException(RequestException::class);

        app(ModrinthProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_required_metrics_are_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => '6jCDxmNc',
                'downloads' => 697,
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('followers');

        app(ModrinthProvider::class)->getProjectMetrics($this->distribution());
    }

    public function test_it_fails_when_required_metrics_are_malformed(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => '6jCDxmNc',
                'downloads' => '697',
                'followers' => 1,
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('downloads');

        app(ModrinthProvider::class)->getProjectMetrics($this->distribution());
    }

    private function distribution(): Distribution
    {
        return new Distribution([
            'provider' => 'modrinth',
            'name' => 'Modrinth',
            'loader' => 'combined',
            'project_identifier' => '6jCDxmNc',
            'listing_url' => 'https://modrinth.com/plugin/enderman-grief-control',
            'active' => true,
        ]);
    }
}
