<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ReportController extends Controller
{
    public function __invoke(Request $request, ProfitReport $report): Response
    {
        $today = CarbonImmutable::today('UTC');
        $request->mergeIfMissing(['start' => $today->subDays(29)->toDateString(), 'end' => $today->toDateString()]);
        /** @var array{start: string, end: string} $validated */
        $validated = $request->validate([
            'start' => ['bail', 'required', 'date', 'date_format:Y-m-d'],
            'end' => ['bail', 'required', 'date', 'date_format:Y-m-d'],
        ]);
        $start = CarbonImmutable::parse($validated['start'], 'UTC');
        $end = CarbonImmutable::parse($validated['end'], 'UTC');
        if ($end < $start) {
            throw ValidationException::withMessages(['end' => 'The end date must be on or after the start date.']);
        }
        if ($end > $today) {
            throw ValidationException::withMessages(['end' => 'The end date must not be after today (UTC).']);
        }
        if ($end >= $start->addDays(92)) {
            throw ValidationException::withMessages(['end' => 'The date range must contain at most 92 days, including both dates.']);
        }

        return Inertia::render('Admin/Report', ['filters' => $validated, 'report' => $report->build($start, $end)]);
    }
}
