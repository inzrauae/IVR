<?php

namespace App\Http\Controllers\OrganizationSettings;

use App\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BusinessHoursController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $validated = $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.is_open' => ['required', 'boolean'],
            'hours.*.opens_at' => ['required_if:hours.*.is_open,true', 'nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['required_if:hours.*.is_open,true', 'nullable', 'date_format:H:i'],
        ]);

        foreach ($validated['hours'] as $i => $day) {
            if ($day['is_open'] && $day['closes_at'] <= $day['opens_at']) {
                return back()->withErrors(["hours.{$i}.closes_at" => BusinessHour::DAYS[$day['day_of_week']].': closing time must be after opening time.']);
            }
        }

        DB::transaction(function () use ($validated) {
            foreach ($validated['hours'] as $day) {
                BusinessHour::updateOrCreate(
                    ['organization_id' => $this->tenant->id(), 'day_of_week' => $day['day_of_week']],
                    [
                        'is_open' => $day['is_open'],
                        'opens_at' => $day['is_open'] ? $day['opens_at'] : null,
                        'closes_at' => $day['is_open'] ? $day['closes_at'] : null,
                    ],
                );
            }
        });

        Audit::record('settings.business_hours_updated', $this->tenant->get());

        return back()->with('success', 'Business hours saved.');
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'recurring' => ['required', 'boolean'],
        ]);

        if (Holiday::whereDate('date', $validated['date'])->exists()) {
            return back()->withErrors(['date' => 'A holiday already exists on this date.']);
        }

        $holiday = Holiday::create($validated);
        Audit::record('settings.holiday_added', $holiday, ['name' => $holiday->name, 'date' => $validated['date']]);

        return back()->with('success', "{$holiday->name} added.");
    }

    public function destroyHoliday(int $holiday): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        // Resolved through the tenant scope, so other organizations' holidays are a 404.
        $record = Holiday::findOrFail($holiday);
        $record->delete();
        Audit::record('settings.holiday_removed', null, ['name' => $record->name]);

        return back()->with('success', "{$record->name} removed.");
    }
}
