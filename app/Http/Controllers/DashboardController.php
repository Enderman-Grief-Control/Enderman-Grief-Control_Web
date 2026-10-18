<?php

namespace App\Http\Controllers;

use App\Analytics\ReadDashboardAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ReadDashboardAnalytics $analytics): Response
    {
        $range = $request->query('historyRange');

        abort_unless($range === null || is_string($range), 422, 'History range must be a string.');

        return Inertia::render('dashboard', [
            'analytics' => fn () => $analytics(),
            'historyRange' => in_array($range, ['7d', '30d', '90d', 'all'], true) ? $range : '30d',
        ]);
    }
}
