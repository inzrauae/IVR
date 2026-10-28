<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Jobs\DeprovisionExtension;
use App\Jobs\ProvisionExtension;
use App\Jobs\PushRouting;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Department;
use App\Models\Extension;
use App\Models\User;
use App\Pbx\Routing\NumberPlan;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExtensionController extends Controller
{
    /** Dialable number: internal extension or external number, optional leading +. */
    private const DESTINATION = 'regex:/^\+?\d{2,20}$/';

    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::EXTENSIONS_VIEW);

        $organization = $this->tenant->get();

        return Inertia::render('extensions/Index', [
            'extensions' => Extension::with(['user:id,name,email', 'department:id,name'])
                ->orderByRaw('CAST(number AS INTEGER)')
                ->get()
                ->map(fn (Extension $e) => $this->summary($e)),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'users' => $this->assignableUsers(),
            'nextNumber' => NumberPlan::next(Extension::FIRST_NUMBER),
            'limit' => ['used' => $organization->extensions()->count(), 'limit' => $organization->extensionLimit()],
            'can' => ['manage' => $request->user()->can(Permission::EXTENSIONS_MANAGE)],
        ]);
    }

    public function show(Request $request, int $extension): Response
    {
        Gate::authorize(Permission::EXTENSIONS_VIEW);

        $record = Extension::with(['user:id,name,email', 'department:id,name'])->findOrFail($extension);
        $server = $this->tenant->get()->pbxServer;

        return Inertia::render('extensions/Show', [
            'extension' => [
                ...$this->summary($record),
                'caller_id_name' => $record->caller_id_name,
                'caller_id_number' => $record->caller_id_number,
                'webrtc_enabled' => $record->webrtc_enabled,
                'voicemail_enabled' => $record->voicemail_enabled,
                'do_not_disturb' => $record->do_not_disturb,
                'ring_timeout' => $record->ring_timeout,
                'forward_always' => $record->forward_always,
                'forward_busy' => $record->forward_busy,
                'forward_no_answer' => $record->forward_no_answer,
                'sip_username' => $record->sip_username,
                'registered_contact' => $record->registered_contact,
                'registered_user_agent' => $record->registered_user_agent,
                'registration_changed_at' => $record->registration_changed_at?->toIso8601String(),
                'provisioned_at' => $record->provisioned_at?->toIso8601String(),
            ],
            'server' => $server ? ['sip_domain' => $server->sip_domain, 'wss_url' => $server->wss_url] : null,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'users' => $this->assignableUsers($record),
            'activity' => AuditLog::where('auditable_type', $record->getMorphClass())
                ->where('auditable_id', $record->id)
                ->where('organization_id', $record->organization_id)
                ->with('user:id,name')
                ->latest('id')
                ->limit(20)
                ->get(['id', 'user_id', 'action', 'metadata', 'created_at']),
            'recentCalls' => Call::where(fn ($q) => $q->where('from_extension_id', $record->id)->orWhere('to_extension_id', $record->id))
                ->latest('started_at')
                ->limit(10)
                ->get(['id', 'direction', 'status', 'from_number', 'from_name', 'to_number', 'started_at', 'duration']),
            'defaultRingTimeout' => $this->tenant->get()->setting('call.ring_timeout'),
            'can' => ['manage' => $request->user()->can(Permission::EXTENSIONS_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::EXTENSIONS_MANAGE);

        $organization = $this->tenant->get();

        $validated = $request->validate([
            'number' => ['nullable', 'regex:/^\d{2,10}$/', Rule::unique(Extension::class)->where('organization_id', $organization->id),
                function (string $attribute, mixed $value, \Closure $fail) {
                    // Ring groups, queues, conferences and IVR menus share the number space.
                    if (is_string($value) && ($owner = NumberPlan::owner($value)) && $owner !== 'extension') {
                        $fail("{$value} is already used by a {$owner}.");
                    }
                }],
            ...$this->rules(),
        ], ['number.unique' => 'This extension number is already in use.']);

        if (! $organization->hasExtensionCapacity()) {
            return back()->with('error', 'Your plan\'s extension limit has been reached. Upgrade your package to add more extensions.');
        }

        $extension = Extension::create([
            ...$validated,
            'number' => $validated['number'] ?? NumberPlan::next(Extension::FIRST_NUMBER),
        ]);

        Audit::record('extension.created', $extension, ['number' => $extension->number]);
        ProvisionExtension::dispatch($extension->id);

        return to_route('extensions.show', $extension->id)->with('success', "Extension {$extension->number} created. Provisioning on the phone system…");
    }

    public function update(Request $request, int $extension): RedirectResponse
    {
        Gate::authorize(Permission::EXTENSIONS_MANAGE);

        $record = Extension::findOrFail($extension);

        $validated = $request->validate([
            ...$this->rules($record),
            'status' => ['required', Rule::in([Extension::STATUS_ACTIVE, Extension::STATUS_DISABLED])],
        ]);

        $record->fill($validated);
        $changes = array_keys($record->getDirty());

        if ($changes === []) {
            return back()->with('success', 'No changes to save.');
        }

        $action = match (true) {
            ! in_array('status', $changes, true) => 'extension.updated',
            $record->isActive() => 'extension.enabled',
            default => 'extension.disabled',
        };

        $record->save();
        $record->markProvisioningPending();

        Audit::record($action, $record, ['changed' => $changes]);
        ProvisionExtension::dispatch($record->id);

        // Ring groups and voicemail destinations skip disabled extensions and mailboxes.
        if (array_intersect($changes, ['status', 'voicemail_enabled'])) {
            PushRouting::forCurrentTenant();
        }

        return back()->with('success', "Extension {$record->number} saved.");
    }

    public function destroy(int $extension): RedirectResponse
    {
        Gate::authorize(Permission::EXTENSIONS_MANAGE);

        $record = Extension::findOrFail($extension);
        $serverId = $this->tenant->get()->pbx_server_id;

        Audit::record('extension.deleted', $record, ['number' => $record->number, 'name' => $record->name]);
        $record->delete();
        PushRouting::forCurrentTenant();

        if ($serverId) {
            DeprovisionExtension::dispatch($serverId, $record->sip_username);
        }

        return to_route('extensions.index')->with('success', "Extension {$record->number} deleted.");
    }

    public function retry(int $extension): RedirectResponse
    {
        Gate::authorize(Permission::EXTENSIONS_MANAGE);

        $record = Extension::findOrFail($extension);
        $record->markProvisioningPending();

        Audit::record('extension.provisioning_retried', $record);
        ProvisionExtension::dispatch($record->id);

        return back()->with('success', "Provisioning of extension {$record->number} restarted.");
    }

    /**
     * Show the SIP password once, on explicit request, and log who looked.
     */
    public function credentials(int $extension): RedirectResponse
    {
        Gate::authorize(Permission::EXTENSIONS_MANAGE);

        $record = Extension::findOrFail($extension);
        Audit::record('extension.credentials_viewed', $record);

        return back()->with('credentials', ['username' => $record->sip_username, 'password' => $record->sip_password]);
    }

    public function regeneratePassword(int $extension): RedirectResponse
    {
        Gate::authorize(Permission::EXTENSIONS_MANAGE);

        $record = Extension::findOrFail($extension);
        $record->forceFill(['sip_password' => Extension::generatePassword()])->save();
        $record->markProvisioningPending();

        Audit::record('extension.password_regenerated', $record);
        ProvisionExtension::dispatch($record->id);

        return back()
            ->with('success', 'New SIP password generated. Devices using the old password will stop registering.')
            ->with('credentials', ['username' => $record->sip_username, 'password' => $record->sip_password]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(?Extension $current = null): array
    {
        $organizationId = $this->tenant->id();
        $notSelf = $current ? 'not_in:'.$current->number : 'nullable';

        return [
            'name' => ['required', 'string', 'max:255'],
            'user_id' => [
                'nullable', 'integer',
                Rule::exists(User::class, 'id')->where('organization_id', $organizationId),
                Rule::unique(Extension::class)->where('organization_id', $organizationId)->ignore($current?->id),
            ],
            'department_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id')->where('organization_id', $organizationId)],
            'caller_id_name' => ['nullable', 'string', 'max:64'],
            'caller_id_number' => ['nullable', 'regex:/^\+?\d{3,20}$/'],
            'webrtc_enabled' => ['boolean'],
            'voicemail_enabled' => ['boolean'],
            'do_not_disturb' => ['boolean'],
            'ring_timeout' => ['nullable', 'integer', 'between:5,120'],
            'forward_always' => ['nullable', self::DESTINATION, $notSelf],
            'forward_busy' => ['nullable', self::DESTINATION, $notSelf],
            'forward_no_answer' => ['nullable', self::DESTINATION, $notSelf],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Extension $e): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'name' => $e->name,
            'status' => $e->status,
            'user' => $e->user?->only(['id', 'name', 'email']),
            'department' => $e->department?->only(['id', 'name']),
            'user_id' => $e->user_id,
            'department_id' => $e->department_id,
            'registered' => $e->registered,
            'do_not_disturb' => $e->do_not_disturb,
            'forwarding' => (bool) $e->forward_always,
            'provisioning_status' => $e->provisioning_status,
            'provisioning_error' => $e->provisioning_error,
        ];
    }

    /**
     * Users without an extension, plus the extension's current user.
     *
     * @return list<array{id: int, name: string}>
     */
    private function assignableUsers(?Extension $current = null): array
    {
        $taken = Extension::whereNotNull('user_id')->when($current, fn ($q) => $q->whereKeyNot($current->id))->pluck('user_id');

        return $this->tenant->get()->users()
            ->whereNotIn('id', $taken)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }
}
