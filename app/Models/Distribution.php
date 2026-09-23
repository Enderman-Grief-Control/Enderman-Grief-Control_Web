<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $name
 * @property string $loader
 * @property string $project_identifier
 * @property string $listing_url
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MetricSnapshot> $metricSnapshots
 */
#[Fillable(['provider', 'name', 'loader', 'project_identifier', 'listing_url', 'active'])]
class Distribution extends Model
{
    /**
     * @return HasMany<MetricSnapshot, $this>
     */
    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
