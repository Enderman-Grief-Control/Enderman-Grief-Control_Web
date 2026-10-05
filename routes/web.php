<?php

use App\Analytics\ReadDashboardAnalytics;
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
});

require __DIR__.'/settings.php';
