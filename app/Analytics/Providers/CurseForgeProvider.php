<?php

namespace App\Analytics\Providers;

use App\Analytics\Contracts\DistributionProvider;
use App\Analytics\ProjectMetrics;
use App\Models\Distribution;
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
            downloads: $this->integerMetric($data, 'downloadCount'),
            followers: null,
            likes: null,
        );
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
    private function integerMetric(array $payload, string $field): int
    {
        if (! array_key_exists($field, $payload) || ! is_int($payload[$field])) {
            throw new UnexpectedValueException("CurseForge mod response field [{$field}] must be an integer.");
        }

        return $payload[$field];
    }
}
