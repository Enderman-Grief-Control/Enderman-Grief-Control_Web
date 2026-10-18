<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DistributionVersionExclusionController;
use App\Http\Controllers\DownloadHistoryController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('dashboard/history', DownloadHistoryController::class)->name('dashboard.history');

    Route::post('dashboard/versions/{distributionVersion}/exclusion', [DistributionVersionExclusionController::class, 'store'])
        ->name('dashboard.versions.exclusion.store');
    Route::delete('dashboard/versions/{distributionVersion}/exclusion', [DistributionVersionExclusionController::class, 'destroy'])
        ->name('dashboard.versions.exclusion.destroy');
});

require __DIR__.'/settings.php';
