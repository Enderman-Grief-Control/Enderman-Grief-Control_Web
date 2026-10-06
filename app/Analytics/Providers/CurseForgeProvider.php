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

final readonly class CurseForgeProvider implements DistributionProvider
{
    public function __construct(private HttpFactory $http) {}

    public function getProjectMetrics(Distribution $distribution): ProjectMetrics
    {
        $response = $this->client()
            ->get("/v1/mods/{$distribution->project_identifier}")
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new UnexpectedValueException('CurseForge mod response must be a JSON object.');
        }

        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new UnexpectedValueException('CurseForge mod response field [data] must be a JSON object.');
        }

        return new ProjectMetrics(
            downloads: $this->integerMetric($data, 'downloadCount', 'CurseForge mod response'),
            followers: null,
            likes: null,
        );
    }

    /**
     * @return list<DistributionVersionMetrics>
     */
    public function getVersionMetrics(Distribution $distribution): array
    {
        $capturedAt = CarbonImmutable::now();
        $versions = [];
        $index = 0;
        $pageSize = 50;

        do {
            $response = $this->client()
                ->get("/v1/mods/{$distribution->project_identifier}/files", [
                    'index' => $index,
                    'pageSize' => $pageSize,
                ])
                ->throw()
                ->json();

            if (! is_array($response)) {
                throw new UnexpectedValueException('CurseForge mod files response must be a JSON object.');
            }

            $data = $response['data'] ?? null;

            if (! is_array($data)) {
                throw new UnexpectedValueException('CurseForge mod files response field [data] must be a JSON array.');
            }

            /** @var list<array<array-key, mixed>> $data */
            foreach ($data as $file) {
                $versions[] = $this->versionMetrics($file, $distribution, $capturedAt);
            }

            $pagination = $response['pagination'] ?? null;

            if (! is_array($pagination)) {
                throw new UnexpectedValueException('CurseForge mod files response field [pagination] must be a JSON object.');
            }

            $pageIndex = $this->integerMetric($pagination, 'index', 'CurseForge mod files response pagination');
            $resultCount = $this->integerMetric($pagination, 'resultCount', 'CurseForge mod files response pagination');
            $totalCount = $this->integerMetric($pagination, 'totalCount', 'CurseForge mod files response pagination');

            if ($resultCount <= 0) {
                break;
            }

            $index = $pageIndex + $resultCount;
        } while ($index < $totalCount);

        return $versions;
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->withHeader('x-api-key', $this->apiKey());
    }

    private function baseUrl(): string
    {
        $baseUrl = config('services.curseforge.base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new UnexpectedValueException('CurseForge API base URL must be configured.');
        }

        return rtrim($baseUrl, '/');
    }

    private function apiKey(): string
    {
        $apiKey = config('services.curseforge.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new UnexpectedValueException('CurseForge API key must be configured.');
        }

        return $apiKey;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function versionMetrics(array $payload, Distribution $distribution, CarbonImmutable $capturedAt): DistributionVersionMetrics
    {
        return new DistributionVersionMetrics(
            providerVersionIdentifier: (string) $this->integerMetric($payload, 'id', 'CurseForge file response'),
            versionNumber: null,
            displayName: $this->stringMetric($payload, 'displayName', 'CurseForge file response'),
            loaders: [$distribution->loader],
            gameVersions: $this->stringListMetric($payload, 'gameVersions', 'CurseForge file response'),
            downloads: $this->integerMetric($payload, 'downloadCount', 'CurseForge file response'),
            publishedAt: $this->dateTimeMetric($payload, 'fileDate', 'CurseForge file response'),
            capturedAt: $capturedAt,
            metadata: $this->fileMetadata($payload),
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
        return CarbonImmutable::parse($this->stringMetric($payload, $field, $context));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function fileMetadata(array $payload): ?array
    {
        $metadata = [];

        if (array_key_exists('fileName', $payload) && is_string($payload['fileName']) && trim($payload['fileName']) !== '') {
            $metadata['file_name'] = $payload['fileName'];
        }

        if (array_key_exists('releaseType', $payload) && is_int($payload['releaseType'])) {
            $metadata['release_type'] = $payload['releaseType'];
        }

        return $metadata === [] ? null : $metadata;
    }
}
