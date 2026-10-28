<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Jobs\ProvisionTrunk;
use App\Jobs\PushRouting;
use App\Jobs\RemovePbxObjects;
use App\Models\SipTrunk;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrunkController extends Controller
{
    public const CODECS = ['ulaw,alaw', 'alaw,ulaw', 'g722,ulaw,alaw', 'opus,ulaw,alaw', 'g729,ulaw,alaw'];

    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::TRUNKS_VIEW);

        return Inertia::render('trunks/Index', [
            'trunks' => SipTrunk::withCount(['didNumbers', 'outboundRoutes'])->orderBy('name')->get()->map(fn (SipTrunk $t) => [
                ...$t->only(['id', 'name', 'host', 'port', 'transport', 'username', 'register', 'from_domain', 'caller_id', 'max_channels', 'codecs', 'status', 'provisioning_status', 'provisioning_error', 'did_numbers_count', 'outbound_routes_count']),
                'has_password' => filled($t->password),
                'provisioned_at' => $t->provisioned_at?->toIso8601String(),
            ]),
            'codecs' => self::CODECS,
            'can' => ['manage' => $request->user()->can(Permission::TRUNKS_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::TRUNKS_MANAGE);

        $trunk = SipTrunk::create($this->validated($request));
        $this->push($trunk);
        Audit::record('trunk.created', $trunk, ['name' => $trunk->name, 'host' => $trunk->host]);

        return back()->with('success', "{$trunk->name} added. It is being set up on the PBX.");
    }

    public function update(Request $request, int $trunk): RedirectResponse
    {
        Gate::authorize(Permission::TRUNKS_MANAGE);

        $record = SipTrunk::findOrFail($trunk);
        $validated = $this->validated($request, $record);

        // A blank password on edit keeps the stored one.
        if (($validated['password'] ?? null) === null) {
            unset($validated['password']);
        }

        $record->update($validated);
        $this->push($record);
        Audit::record('trunk.updated', $record, ['fields' => array_keys($record->getChanges())]);

        return back()->with('success', "{$record->name} saved.");
    }

    public function destroy(int $trunk): RedirectResponse
    {
        Gate::authorize(Permission::TRUNKS_MANAGE);

        $record = SipTrunk::findOrFail($trunk);

        if ($server = $this->tenant->get()->pbxServer) {
            RemovePbxObjects::dispatch($server->id, [], [$record->endpointId()]);
        }

        Audit::record('trunk.deleted', $record, ['name' => $record->name]);
        $record->delete();
        PushRouting::forCurrentTenant();

        return back()->with('success', "{$record->name} deleted. Its outbound routes were removed and its numbers are now unassigned.");
    }

    public function retry(int $trunk): RedirectResponse
    {
        Gate::authorize(Permission::TRUNKS_MANAGE);

        $record = SipTrunk::findOrFail($trunk);
        $this->push($record);

        return back()->with('success', "Retrying {$record->name}.");
    }

    private function push(SipTrunk $trunk): void
    {
        $trunk->markProvisioningPending();
        ProvisionTrunk::dispatch($trunk->id);
        // Outbound routes skip disabled trunks, so routing changes with trunk status.
        PushRouting::forCurrentTenant();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SipTrunk $current = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(SipTrunk::class)->where('organization_id', $this->tenant->id())->ignore($current?->id)],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'transport' => ['required', Rule::in(SipTrunk::TRANSPORTS)],
            'username' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.+\-]+$/', 'required_if:register,true'],
            'password' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $request->boolean('register') && ! $current?->password)],
            'register' => ['boolean'],
            'from_domain' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'caller_id' => ['nullable', 'string', 'regex:/^\+?\d{4,15}$/'],
            'max_channels' => ['required', 'integer', 'between:0,1000'],
            'codecs' => ['required', Rule::in(self::CODECS)],
            'status' => ['required', Rule::in(['active', 'disabled'])],
        ], [
            'host.regex' => 'Enter a hostname or IP address, without sip: or a port.',
        ]);

        return [...$validated, 'register' => (bool) ($validated['register'] ?? false)];
    }
}
