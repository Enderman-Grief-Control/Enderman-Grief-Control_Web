<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distribution_id
 * @property string $provider_version_identifier
 * @property string|null $version_number
 * @property string $display_name
 * @property array<int, string> $loaders
 * @property array<int, string> $game_versions
 * @property Carbon|null $published_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $excluded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Distribution $distribution
 * @property-read Collection<int, DistributionVersionSnapshot> $snapshots
 */
#[Fillable([
    'distribution_id',
    'provider_version_identifier',
    'version_number',
    'display_name',
    'loaders',
    'game_versions',
    'published_at',
    'metadata',
    'excluded_at',
])]
class DistributionVersion extends Model
{
    /**
     * @return BelongsTo<Distribution, $this>
     */
    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
    }

    /**
     * @return HasMany<DistributionVersionSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(DistributionVersionSnapshot::class);
    }

    /**
     * Versions/files the maintainer has not removed from the dashboard.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->whereNull('excluded_at');
    }

    /**
     * Versions/files the maintainer has removed from the dashboard.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function excluded(Builder $query): void
    {
        $query->whereNotNull('excluded_at');
    }

    public function isAvailable(): bool
    {
        return $this->excluded_at === null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'loaders' => 'array',
            'game_versions' => 'array',
            'metadata' => 'array',
            'published_at' => 'datetime',
            'excluded_at' => 'datetime',
        ];
    }
}
