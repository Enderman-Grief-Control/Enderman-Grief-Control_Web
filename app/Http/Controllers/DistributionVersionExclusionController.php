<?php

namespace App\Http\Controllers;

use App\Analytics\LatestCollection;
use App\Models\DistributionVersion;
use App\Models\DistributionVersionSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Maintainer removal and restoration of versions/files on the dashboard.
 *
 * Both actions only toggle excluded_at. Snapshot history is never deleted and
 * providers are never called; a removed version/file that reappears in a
 * later collection is restored by the collection write path.
 */
class DistributionVersionExclusionController extends Controller
{
    /**
     * Remove a Missing version/file from the available set.
     */
    public function store(DistributionVersion $distributionVersion): RedirectResponse
    {
        $this->ensureActiveDistribution($distributionVersion);

        if (! $distributionVersion->isAvailable()) {
            throw ValidationException::withMessages([
                'version' => __('This version/file is already removed.'),
            ]);
        }

        if (! $this->isMissing($distributionVersion, LatestCollection::current())) {
            throw ValidationException::withMessages([
                'version' => __('Only versions/files missing from the latest collection can be removed.'),
            ]);
        }

        $distributionVersion->update(['excluded_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version/file removed.')]);

        return back();
    }

    /**
     * Restore a removed version/file. Restoring an available one is a no-op.
     */
    public function destroy(DistributionVersion $distributionVersion): RedirectResponse
    {
        $this->ensureActiveDistribution($distributionVersion);

        if ($distributionVersion->isAvailable()) {
            return back();
        }

        $distributionVersion->update(['excluded_at' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version/file restored.')]);

        return back();
    }

    private function ensureActiveDistribution(DistributionVersion $distributionVersion): void
    {
        abort_unless($distributionVersion->distribution->active, 404);
    }

    /**
     * Matches ReadDistributionVersionAnalytics: Missing means the newest
     * snapshot (ties to the highest id) is outside the latest collection.
     */
    private function isMissing(DistributionVersion $distributionVersion, LatestCollection $latestCollection): bool
    {
        /** @var DistributionVersionSnapshot|null $latestSnapshot */
        $latestSnapshot = $distributionVersion->snapshots()
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        return ! $latestCollection->contains($latestSnapshot?->captured_at);
    }
}
