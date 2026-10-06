<?php

namespace App\Analytics\Contracts;

use App\Analytics\DistributionVersionMetrics;
use App\Analytics\ProjectMetrics;
use App\Models\Distribution;

interface DistributionProvider
{
    public function getProjectMetrics(Distribution $distribution): ProjectMetrics;

    /**
     * @return list<DistributionVersionMetrics>
     */
    public function getVersionMetrics(Distribution $distribution): array;
}
