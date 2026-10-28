<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Jobs\PushRouting;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\OutboundRoute;
use App\Models\SipTrunk;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outbound routes (which trunk carries which numbers) plus the organization's routing status.
 * Inbound routing lives on each DID; open hours come from organization settings.
 */
class RoutingController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ROUTING_VIEW);

        $organization = $this->tenant->get();
        $hours = BusinessHour::orderBy('day_of_week')->get()->keyBy('day_of_week');

        return Inertia::render('routing/Index', [
            'routes' => OutboundRoute::with('trunk:id,name,status')->orderBy('priority')->orderBy('id')->get()->map(fn (OutboundRoute $r) => [
                ...$r->only(['id', 'name', 'pattern', 'strip_digits', 'prepend', 'priority', 'active', 'sip_trunk_id']),
                'trunk' => $r->trunk?->name,
                'trunk_active' => $r->trunk?->isActive() ?? false,
            ]),
            'trunks' => SipTrunk::orderBy('name')->get(['id', 'name', 'status']),
            'status' => [
                'pushed_at' => $organization->pbx_routing_pushed_at?->toIso8601String(),
                'error' => $organization->pbx_routing_error,
                'keys' => count($organization->pbx_routing_keys ?? []),
            ],
            'hours' => collect(range(0, 6))->map(fn (int $day) => [
                'day' => BusinessHour::DAYS[$day],
                'open' => isset($hours[$day]) ? $hours[$day]->is_open : ($day >= 1 && $day <= 5),
                'from' => substr($hours[$day]->opens_at ?? '09:00', 0, 5),
                'to' => substr($hours[$day]->closes_at ?? '17:00', 0, 5),
            ]),
            'holidays' => Holiday::where(fn ($q) => $q->where('date', '>=', now()->startOfDay())->orWhere('recurring', true))->orderBy('date')->limit(10)->get(['name', 'date', 'recurring'])
                ->map(fn ($h) => ['name' => $h->name, 'date' => $h->date->toDateString(), 'recurring' => $h->recurring]),
            'timezone' => $organization->timezone,
            'can' => ['manage' => $request->user()->can(Permission::ROUTING_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ROUTING_MANAGE);

        $route = OutboundRoute::create($this->validated($request));
        PushRouting::forCurrentTenant();
        Audit::record('route.created', $route, ['name' => $route->name, 'pattern' => $route->pattern]);

        return back()->with('success', "Route {$route->name} added.");
    }

    public function update(Request $request, int $route): RedirectResponse
    {
        Gate::authorize(Permission::ROUTING_MANAGE);

        $record = OutboundRoute::findOrFail($route);
        $record->update($this->validated($request));
        PushRouting::forCurrentTenant();
        Audit::record('route.updated', $record, ['fields' => array_keys($record->getChanges())]);

        return back()->with('success', "Route {$record->name} saved.");
    }

    public function destroy(int $route): RedirectResponse
    {
        Gate::authorize(Permission::ROUTING_MANAGE);

        $record = OutboundRoute::findOrFail($route);
        Audit::record('route.deleted', $record, ['name' => $record->name]);
        $record->delete();
        PushRouting::forCurrentTenant();

        return back()->with('success', "Route {$record->name} deleted.");
    }

    public function publish(): RedirectResponse
    {
        Gate::authorize(Permission::ROUTING_MANAGE);

        PushRouting::forCurrentTenant();

        return back()->with('success', 'Routing is being pushed to the PBX.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'pattern' => ['required', 'string', 'max:50', 'regex:'.OutboundRoute::PATTERN_REGEX],
            'sip_trunk_id' => ['required', 'integer', Rule::exists(SipTrunk::class, 'id')->where('organization_id', $this->tenant->id())],
            'strip_digits' => ['required', 'integer', 'between:0,10'],
            'prepend' => ['nullable', 'string', 'regex:/^\+?\d{1,10}$/'],
            'priority' => ['required', 'integer', 'between:1,999'],
            'active' => ['boolean'],
        ], [
            'pattern.regex' => 'Use digits and X (any digit), Z (1-9), N (2-9), [1-5] ranges, and end with . to match any length.',
        ]);

        return [...$validated, 'pattern' => strtoupper($validated['pattern']), 'active' => (bool) ($validated['active'] ?? true)];
    }
}
