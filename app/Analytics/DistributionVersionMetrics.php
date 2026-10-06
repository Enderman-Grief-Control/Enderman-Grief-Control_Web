<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class DistributionVersionMetrics
{
    public string $providerVersionIdentifier;

    public ?string $versionNumber;

    public string $displayName;

    /**
     * @var list<string>
     */
    public array $loaders;

    /**
     * @var list<string>
     */
    public array $gameVersions;

    public int $downloads;

    public ?CarbonImmutable $publishedAt;

    public CarbonImmutable $capturedAt;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $metadata;

    /**
     * @param  list<string>  $loaders
     * @param  list<string>  $gameVersions
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        string $providerVersionIdentifier,
        ?string $versionNumber,
        string $displayName,
        array $loaders,
        array $gameVersions,
        int $downloads,
        ?DateTimeInterface $publishedAt = null,
        ?DateTimeInterface $capturedAt = null,
        ?array $metadata = null,
    ) {
        $this->providerVersionIdentifier = $this->nonEmptyString('providerVersionIdentifier', $providerVersionIdentifier);
        $this->versionNumber = $versionNumber === null
            ? null
            : $this->nonEmptyString('versionNumber', $versionNumber);
        $this->displayName = $this->nonEmptyString('displayName', $displayName);
        $this->loaders = $this->stringList('loaders', $loaders);
        $this->gameVersions = $this->stringList('gameVersions', $gameVersions);
        $this->assertNonNegative('downloads', $downloads);

        $this->downloads = $downloads;
        $this->publishedAt = $publishedAt === null
            ? null
            : CarbonImmutable::instance($publishedAt);
        $this->capturedAt = $capturedAt === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($capturedAt);
        $this->metadata = $metadata;
    }

    private function nonEmptyString(string $field, string $value): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Distribution version metric [{$field}] must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function stringList(string $field, array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("Distribution version metric [{$field}] must be a list of non-empty strings.");
            }

            $strings[] = $value;
        }

        return $strings;
    }

    private function assertNonNegative(string $metric, int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("Distribution version metric [{$metric}] must be a non-negative integer.");
        }
    }
}
