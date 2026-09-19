<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distribution_id
 * @property int $downloads
 * @property int|null $followers
 * @property int|null $likes
 * @property Carbon $captured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Distribution $distribution
 */
#[Fillable(['distribution_id', 'downloads', 'followers', 'likes', 'captured_at'])]
class MetricSnapshot extends Model
{
    /**
     * @return BelongsTo<Distribution, $this>
     */
    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
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
            'followers' => 'integer',
            'likes' => 'integer',
            'captured_at' => 'datetime',
        ];
    }
}
