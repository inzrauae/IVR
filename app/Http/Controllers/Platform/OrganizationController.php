<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Jobs\DeprovisionExtension;
use App\Jobs\ProvisionExtension;
use App\Jobs\ProvisionTrunk;
use App\Jobs\PushRouting;
use App\Jobs\RemovePbxObjects;
use App\Models\Organization;
use App\Models\PbxServer;
use App\Models\SipTrunk;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        return Inertia::render('platform/Organizations', [
            'organizations' => Organization::query()
                ->with(['currentSubscription.package:id,name', 'pbxServer:id,name'])
                ->withCount('users')
                ->when($search, fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")))
                ->latest()
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Organization $org) => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'slug' => $org->slug,
                    'status' => $org->status,
                    'contact_email' => $org->contact_email,
                    'users_count' => $org->users_count,
                    'package' => $org->currentSubscription?->package?->name,
                    'subscription_status' => $org->currentSubscription?->status,
                    'pbx_server_id' => $org->pbx_server_id,
                    'pbx_server' => $org->pbxServer?->name,
                    'suspension_reason' => $org->suspension_reason,
                    'created_at' => $org->created_at->toIso8601String(),
                ]),
            'pbxServers' => PbxServer::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => ['search' => $search],
        ]);
    }

    public function suspend(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $organization->suspend($validated['reason']);
        Audit::record('organization.suspended', $organization, ['reason' => $validated['reason']], $organization);

        return back()->with('success', "{$organization->name} has been suspended.");
    }

    public function reactivate(Organization $organization): RedirectResponse
    {
        $organization->reactivate();
        Audit::record('organization.reactivated', $organization, [], $organization);

        return back()->with('success', "{$organization->name} has been reactivated.");
    }

    public function assignPbxServer(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'pbx_server_id' => ['nullable', Rule::exists(PbxServer::class, 'id')],
        ]);

        $previousServerId = $organization->pbx_server_id;
        $organization->forceFill(['pbx_server_id' => $validated['pbx_server_id']])->save();
        Audit::record('organization.pbx_server_assigned', $organization, $validated, $organization);

        // Move the organization's extensions: clear them from the old server, push them to the new one.
        if ((int) $previousServerId !== (int) $organization->pbx_server_id) {
            foreach ($organization->extensions()->get() as $extension) {
                if ($previousServerId) {
                    DeprovisionExtension::dispatch($previousServerId, $extension->sip_username);
                }

                if ($organization->pbx_server_id) {
                    $extension->markProvisioningPending();
                    ProvisionExtension::dispatch($extension->id);
                }
            }

            // Trunks and routing globals move the same way.
            $trunks = SipTrunk::withoutGlobalScopes()->where('organization_id', $organization->id)->get();

            if ($previousServerId) {
                RemovePbxObjects::dispatch($previousServerId, $organization->pbx_routing_keys ?? [], $trunks->map->endpointId()->all());
                $organization->forceFill(['pbx_routing_keys' => null])->save();
            }

            if ($organization->pbx_server_id) {
                $trunks->each(function (SipTrunk $trunk) {
                    $trunk->markProvisioningPending();
                    ProvisionTrunk::dispatch($trunk->id);
                });
                PushRouting::dispatch($organization->id);
            }
        }

        return back()->with('success', "PBX server updated for {$organization->name}.");
    }
}
