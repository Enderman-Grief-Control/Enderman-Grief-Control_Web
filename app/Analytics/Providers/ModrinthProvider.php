<?php

namespace App\Analytics\Providers;

use App\Analytics\Contracts\DistributionProvider;
use App\Analytics\ProjectMetrics;
use App\Models\Distribution;
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
            downloads: $this->integerMetric($response, 'downloads'),
            followers: $this->integerMetric($response, 'followers'),
            likes: null,
        );
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
    private function integerMetric(array $payload, string $field): int
    {
        if (! array_key_exists($field, $payload) || ! is_int($payload[$field])) {
            throw new UnexpectedValueException("Modrinth project response field [{$field}] must be an integer.");
        }

        return $payload[$field];
    }
}
