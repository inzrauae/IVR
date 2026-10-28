<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Jobs\PushRouting;
use App\Models\Conference;
use App\Pbx\Routing\NumberPlan;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ConferenceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::CONFERENCES_VIEW);

        $canManage = $request->user()->can(Permission::CONFERENCES_MANAGE);

        return Inertia::render('conferences/Index', [
            // PINs are only shown to people who can change them.
            'rooms' => Conference::orderBy('number')->get()->map(fn (Conference $c) => [
                ...$c->only(['id', 'number', 'name', 'max_members', 'record']),
                'pin' => $canManage ? $c->pin : null,
                'has_pin' => filled($c->pin),
            ]),
            'nextNumber' => NumberPlan::next(800),
            'can' => ['manage' => $canManage],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::CONFERENCES_MANAGE);

        $room = Conference::create($this->validated($request));
        PushRouting::forCurrentTenant();
        Audit::record('conference.created', $room, ['name' => $room->name]);

        return back()->with('success', "{$room->name} created. Dial {$room->number} to join.");
    }

    public function update(Request $request, int $conference): RedirectResponse
    {
        Gate::authorize(Permission::CONFERENCES_MANAGE);

        $room = Conference::findOrFail($conference);
        $room->update($this->validated($request, $room));
        PushRouting::forCurrentTenant();
        Audit::record('conference.updated', $room, ['name' => $room->name]);

        return back()->with('success', "{$room->name} saved.");
    }

    public function destroy(int $conference): RedirectResponse
    {
        Gate::authorize(Permission::CONFERENCES_MANAGE);

        $room = Conference::findOrFail($conference);
        Audit::record('conference.deleted', $room, ['name' => $room->name]);
        $room->delete();
        PushRouting::forCurrentTenant();

        return back()->with('success', "{$room->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Conference $current = null): array
    {
        $validated = $request->validate([
            'number' => NumberPlan::rules($current),
            'name' => ['required', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,10}$/'],
            'max_members' => ['required', 'integer', 'between:2,100'],
            'record' => ['boolean'],
        ], ['pin.regex' => 'Use 4 to 10 digits.']);

        return [...$validated, 'record' => (bool) ($validated['record'] ?? false)];
    }
}
