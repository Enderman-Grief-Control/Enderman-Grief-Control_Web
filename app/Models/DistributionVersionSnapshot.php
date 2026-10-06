<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distribution_version_id
 * @property int $downloads
 * @property Carbon $captured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DistributionVersion $distributionVersion
 */
#[Fillable(['distribution_version_id', 'downloads', 'captured_at'])]
class DistributionVersionSnapshot extends Model
{
    /**
     * @return BelongsTo<DistributionVersion, $this>
     */
    public function distributionVersion(): BelongsTo
    {
        return $this->belongsTo(DistributionVersion::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'downloads' => 'integer',
            'captured_at' => 'datetime',
        ];
    }
}
