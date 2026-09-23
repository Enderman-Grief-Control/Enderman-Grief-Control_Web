<?php

namespace Tests\Feature\Analytics;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduledMetricsCollectionTest extends TestCase
{
    public function test_metrics_collect_is_scheduled_every_six_hours_in_pacific_time(): void
    {
        $this->artisan('schedule:list')
            ->assertExitCode(0);

        $events = $this->app->make(Schedule::class)->events();

        $metricsCollectionEvent = collect($events)->first(
            fn (Event $event): bool => str_contains(
                Event::normalizeCommand($event->command ?? ''),
                'metrics:collect',
            ),
        );

        $this->assertNotNull($metricsCollectionEvent);
        $this->assertSame('0 */6 * * *', $metricsCollectionEvent->getExpression());
        $this->assertSame('America/Los_Angeles', $metricsCollectionEvent->timezone);
    }
}
