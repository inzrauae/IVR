<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Jobs\PushRouting;
use App\Models\Call;
use App\Models\DidNumber;
use App\Models\SipTrunk;
use App\Pbx\Routing\Destination;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DidController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::DIDS_VIEW);

        $since = now()->subDays(30);
        $callCounts = Call::where('started_at', '>=', $since)->whereNotNull('did_number_id')
            ->selectRaw('did_number_id, count(*) as total')->groupBy('did_number_id')->pluck('total', 'did_number_id');

        return Inertia::render('dids/Index', [
            'dids' => DidNumber::with('trunk:id,name')->orderBy('number')->get()->map(fn (DidNumber $d) => [
                ...$d->only(['id', 'number', 'description', 'destination', 'after_hours_destination', 'active', 'sip_trunk_id']),
                'trunk' => $d->trunk?->name,
                'calls_30d' => (int) ($callCounts[$d->id] ?? 0),
            ]),
            'trunks' => SipTrunk::orderBy('name')->get(['id', 'name']),
            'destinations' => Destination::options(),
            'can' => ['manage' => $request->user()->can(Permission::DIDS_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::DIDS_MANAGE);

        $did = DidNumber::create($this->validated($request));
        PushRouting::forCurrentTenant();
        Audit::record('did.created', $did, ['number' => $did->number]);

        return back()->with('success', "+{$did->number} added.");
    }

    public function update(Request $request, int $did): RedirectResponse
    {
        Gate::authorize(Permission::DIDS_MANAGE);

        $record = DidNumber::findOrFail($did);
        $record->update($this->validated($request, $record));
        PushRouting::forCurrentTenant();
        Audit::record('did.updated', $record, ['fields' => array_keys($record->getChanges())]);

        return back()->with('success', "+{$record->number} saved.");
    }

    public function destroy(int $did): RedirectResponse
    {
        Gate::authorize(Permission::DIDS_MANAGE);

        $record = DidNumber::findOrFail($did);
        Audit::record('did.deleted', $record, ['number' => $record->number]);
        $record->delete();
        PushRouting::forCurrentTenant();

        return back()->with('success', "+{$record->number} removed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DidNumber $current = null): array
    {
        $request->merge(['number' => DidNumber::normalize((string) $request->input('number'))]);

        $validated = $request->validate([
            // DIDs are unique across all organizations: a carrier number reaches one tenant.
            'number' => ['required', 'string', 'regex:/^\d{6,15}$/', Rule::unique(DidNumber::class)->ignore($current?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'sip_trunk_id' => ['nullable', 'integer', Rule::exists(SipTrunk::class, 'id')->where('organization_id', $this->tenant->id())],
            'destination' => ['required', 'string', Destination::rule()],
            'after_hours_destination' => ['nullable', 'string', Destination::rule()],
            'active' => ['boolean'],
        ], [
            'number.unique' => 'This number is already in use.',
            'number.regex' => 'Enter the full number with country code, e.g. 94112345678.',
        ]);

        return [...$validated, 'active' => (bool) ($validated['active'] ?? true)];
    }
}
