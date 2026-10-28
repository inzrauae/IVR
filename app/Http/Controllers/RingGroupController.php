<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Jobs\PushRouting;
use App\Models\Extension;
use App\Models\RingGroup;
use App\Pbx\Routing\Destination;
use App\Pbx\Routing\NumberPlan;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RingGroupController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::RING_GROUPS_VIEW);

        return Inertia::render('ring-groups/Index', [
            'groups' => RingGroup::orderBy('number')->get(['id', 'number', 'name', 'strategy', 'ring_timeout', 'members', 'fallback_destination']),
            'extensions' => Extension::orderBy('number')->get(['id', 'number', 'name', 'registered', 'status']),
            'strategies' => RingGroup::STRATEGIES,
            'destinations' => Destination::options(),
            'nextNumber' => NumberPlan::next(600),
            'can' => ['manage' => $request->user()->can(Permission::RING_GROUPS_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::RING_GROUPS_MANAGE);

        $group = RingGroup::create($this->validated($request));
        PushRouting::forCurrentTenant();
        Audit::record('ring_group.created', $group, ['name' => $group->name]);

        return back()->with('success', "{$group->name} created. Dial {$group->number} to ring it.");
    }

    public function update(Request $request, int $ringGroup): RedirectResponse
    {
        Gate::authorize(Permission::RING_GROUPS_MANAGE);

        $group = RingGroup::findOrFail($ringGroup);
        $group->update($this->validated($request, $group));
        PushRouting::forCurrentTenant();
        Audit::record('ring_group.updated', $group, ['name' => $group->name]);

        return back()->with('success', "{$group->name} saved.");
    }

    public function destroy(int $ringGroup): RedirectResponse
    {
        Gate::authorize(Permission::RING_GROUPS_MANAGE);

        $group = RingGroup::findOrFail($ringGroup);
        Audit::record('ring_group.deleted', $group, ['name' => $group->name]);
        $group->delete();
        PushRouting::forCurrentTenant();

        return back()->with('success', "{$group->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?RingGroup $current = null): array
    {
        $validated = $request->validate([
            'number' => NumberPlan::rules($current),
            'name' => ['required', 'string', 'max:100'],
            'strategy' => ['required', Rule::in(array_keys(RingGroup::STRATEGIES))],
            'ring_timeout' => ['required', 'integer', 'between:5,120'],
            'members' => ['required', 'array', 'min:1', 'max:50'],
            'members.*' => ['integer', 'distinct', Rule::exists(Extension::class, 'id')->where('organization_id', $this->tenant->id())],
            'fallback_destination' => ['nullable', 'string', Destination::rule()],
        ]);

        if ($current && ($validated['fallback_destination'] ?? null) === "ring_group:{$current->id}") {
            $validated['fallback_destination'] = null;
        }

        return [...$validated, 'members' => array_map('intval', $validated['members'])];
    }
}
