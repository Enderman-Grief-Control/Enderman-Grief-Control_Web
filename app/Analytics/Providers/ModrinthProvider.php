<?php

namespace App\Analytics\Providers;

use App\Analytics\Contracts\DistributionProvider;
use App\Analytics\DistributionVersionMetrics;
use App\Analytics\ProjectMetrics;
use App\Models\Distribution;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use UnexpectedValueException;

final readonly class ModrinthProvider implements DistributionProvider
{
    public function __construct(private HttpFactory $http) {}

    public function getProjectMetrics(Distribution $distribution): ProjectMetrics
    {
        $response = $this->client()
            ->get("/project/{$distribution->project_identifier}")
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new UnexpectedValueException('Modrinth project response must be a JSON object.');
        }

        return new ProjectMetrics(
            downloads: $this->integerMetric($response, 'downloads', 'Modrinth project response'),
            followers: $this->integerMetric($response, 'followers', 'Modrinth project response'),
            likes: null,
        );
    }

    /**
     * @return list<DistributionVersionMetrics>
     */
    public function getVersionMetrics(Distribution $distribution): array
    {
        $response = $this->client()
            ->get("/project/{$distribution->project_identifier}/version")
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new UnexpectedValueException('Modrinth project versions response must be a JSON array.');
        }

        $capturedAt = CarbonImmutable::now();
        $versions = [];

        /** @var list<array<array-key, mixed>> $response */
        foreach ($response as $version) {
            $versions[] = $this->versionMetrics($version, $capturedAt);
        }

        return $versions;
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->withUserAgent($this->userAgent());
    }

    private function baseUrl(): string
    {
        $baseUrl = config('services.modrinth.base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new UnexpectedValueException('Modrinth API base URL must be configured.');
        }

        return rtrim($baseUrl, '/');
    }

    private function userAgent(): string
    {
        $userAgent = config('services.modrinth.user_agent');

        if (! is_string($userAgent) || trim($userAgent) === '') {
            throw new UnexpectedValueException('Modrinth User-Agent must be configured.');
        }

        return $userAgent;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function versionMetrics(array $payload, CarbonImmutable $capturedAt): DistributionVersionMetrics
    {
        return new DistributionVersionMetrics(
            providerVersionIdentifier: $this->stringMetric($payload, 'id', 'Modrinth version response'),
            versionNumber: $this->stringMetric($payload, 'version_number', 'Modrinth version response'),
            displayName: $this->stringMetric($payload, 'name', 'Modrinth version response'),
            loaders: $this->stringListMetric($payload, 'loaders', 'Modrinth version response'),
            gameVersions: $this->stringListMetric($payload, 'game_versions', 'Modrinth version response'),
            downloads: $this->integerMetric($payload, 'downloads', 'Modrinth version response'),
            publishedAt: $this->dateTimeMetric($payload, 'date_published', 'Modrinth version response'),
            capturedAt: $capturedAt,
            metadata: $this->versionMetadata($payload),
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function integerMetric(array $payload, string $field, string $context): int
    {
        if (! array_key_exists($field, $payload) || ! is_int($payload[$field])) {
            throw new UnexpectedValueException("{$context} field [{$field}] must be an integer.");
        }

        return $payload[$field];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function stringMetric(array $payload, string $field, string $context): string
    {
        if (! array_key_exists($field, $payload) || ! is_string($payload[$field]) || trim($payload[$field]) === '') {
            throw new UnexpectedValueException("{$context} field [{$field}] must be a non-empty string.");
        }

        return $payload[$field];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function stringListMetric(array $payload, string $field, string $context): array
    {
        if (! array_key_exists($field, $payload) || ! is_array($payload[$field])) {
            throw new UnexpectedValueException("{$context} field [{$field}] must be a JSON array of strings.");
        }

        /** @var list<string> $values */
        $values = array_values($payload[$field]);

        return $values;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function dateTimeMetric(array $payload, string $field, string $context): CarbonImmutable
    {
        $value = $this->stringMetric($payload, $field, $context);

        return CarbonImmutable::parse($value);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function versionMetadata(array $payload): ?array
    {
        $versionType = $payload['version_type'] ?? null;

        if (is_string($versionType) && trim($versionType) !== '') {
            return ['version_type' => $versionType];
        }

        return null;
    }
}
