<?php

namespace App\Analytics\Contracts;

use App\Analytics\ProjectMetrics;
use App\Models\Distribution;

interface DistributionProvider
{
    public function getProjectMetrics(Distribution $distribution): ProjectMetrics;
}
