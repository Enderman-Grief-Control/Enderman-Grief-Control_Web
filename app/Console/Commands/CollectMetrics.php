<?php

namespace App\Console\Commands;

use App\Analytics\Contracts\DistributionProvider;
use App\Analytics\Providers\CurseForgeProvider;
use App\Analytics\Providers\ModrinthProvider;
use App\Analytics\RecordMetricSnapshot;
use App\Models\Distribution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class CollectMetrics extends Command
{
    private const SUPPORTED_PROVIDERS = [
        'modrinth',
        'curseforge',
    ];

    protected $signature = 'metrics:collect';

    protected $description = 'Collect distribution metrics and store historical snapshots.';

    public function handle(RecordMetricSnapshot $recordMetricSnapshot): int
    {
        $distributions = Distribution::query()
            ->where('active', true)
            ->whereIn('provider', self::SUPPORTED_PROVIDERS)
            ->orderBy('id')
            ->get();

        if ($distributions->isEmpty()) {
            $this->error('No active supported distributions were found.');

            return self::FAILURE;
        }

        foreach ($distributions as $distribution) {
            try {
                $metrics = $this->providerFor($distribution)->getProjectMetrics($distribution);

                $recordMetricSnapshot($distribution, $metrics);

                $this->info("Collected {$distribution->provider}/{$distribution->name}: {$metrics->downloads} downloads.");
            } catch (Throwable $exception) {
                Log::error('Metric collection failed.', [
                    'distribution_id' => $distribution->id,
                    'provider' => $distribution->provider,
                    'name' => $distribution->name,
                    'exception' => $exception,
                ]);

                $this->error("Failed to collect {$distribution->provider}/{$distribution->name}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function providerFor(Distribution $distribution): DistributionProvider
    {
        return match ($distribution->provider) {
            'curseforge' => app(CurseForgeProvider::class),
            'modrinth' => app(ModrinthProvider::class),
            default => throw new UnexpectedValueException(
                "Unsupported distribution provider [{$distribution->provider}]."
            ),
        };
    }
}
