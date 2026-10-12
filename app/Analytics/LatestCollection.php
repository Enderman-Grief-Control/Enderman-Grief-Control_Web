<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The latest collection run, identified by time instead of a run ID.
 *
 * A snapshot belongs to the latest collection when it was captured within
 * WINDOW_MINUTES before the anchor (the newest aggregate capture across active
 * distributions), inclusive on both ends.
 */
final readonly class LatestCollection
{
    public const WINDOW_MINUTES = 30;

    public ?CarbonImmutable $capturedAt;

    public ?CarbonImmutable $windowStartedAt;

    public function __construct(?CarbonInterface $capturedAt)
    {
        $this->capturedAt = $capturedAt === null ? null : CarbonImmutable::instance($capturedAt);
        $this->windowStartedAt = $this->capturedAt?->subMinutes(self::WINDOW_MINUTES);
    }

    public function contains(?CarbonInterface $capturedAt): bool
    {
        if ($capturedAt === null || $this->capturedAt === null || $this->windowStartedAt === null) {
            return false;
        }

        return $capturedAt->greaterThanOrEqualTo($this->windowStartedAt)
            && $capturedAt->lessThanOrEqualTo($this->capturedAt);
    }

    /**
     * @return array{capturedAt: string|null, windowStartedAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'capturedAt' => $this->capturedAt?->toJSON(),
            'windowStartedAt' => $this->windowStartedAt?->toJSON(),
        ];
    }
}
