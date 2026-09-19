<?php

namespace App\Analytics;

use DateTimeInterface;
use Illuminate\Support\CarbonImmutable;
use InvalidArgumentException;

final readonly class ProjectMetrics
{
    public CarbonImmutable $capturedAt;

    public function __construct(
        public int $downloads,
        public ?int $followers = null,
        public ?int $likes = null,
        ?DateTimeInterface $capturedAt = null,
    ) {
        $this->assertNonNegative('downloads', $downloads);
        $this->assertNullableNonNegative('followers', $followers);
        $this->assertNullableNonNegative('likes', $likes);

        $this->capturedAt = $capturedAt === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($capturedAt);
    }

    private function assertNonNegative(string $metric, int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("Metric [{$metric}] must be a non-negative integer.");
        }
    }

    private function assertNullableNonNegative(string $metric, ?int $value): void
    {
        if ($value === null) {
            return;
        }

        $this->assertNonNegative($metric, $value);
    }
}
