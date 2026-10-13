<?php

use App\Analytics\ReadDashboardAnalytics;
use App\Http\Controllers\DistributionVersionExclusionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get(
        'dashboard',
        fn (ReadDashboardAnalytics $analytics) => Inertia::render(
            'dashboard',
            ['analytics' => $analytics()]
        )
    )->name('dashboard');

    Route::post('dashboard/versions/{distributionVersion}/exclusion', [DistributionVersionExclusionController::class, 'store'])
        ->name('dashboard.versions.exclusion.store');
    Route::delete('dashboard/versions/{distributionVersion}/exclusion', [DistributionVersionExclusionController::class, 'destroy'])
        ->name('dashboard.versions.exclusion.destroy');
});

require __DIR__.'/settings.php';
