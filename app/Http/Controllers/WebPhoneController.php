<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Call;
use App\Models\Contact;
use App\Support\Audit;
use App\Support\CallRows;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Browser softphone. The page never embeds the SIP password; the phone fetches it with an
 * uncacheable request when it connects.
 */
class WebPhoneController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::WEBPHONE_USE);

        $extension = $request->user()->extension;
        $server = $this->tenant->get()->pbxServer()->first();

        return Inertia::render('web-phone/Index', [
            'extension' => $extension?->only(['id', 'number', 'name', 'webrtc_enabled', 'provisioning_status', 'status']),
            'server' => $server ? [
                'wss_url' => $server->wss_url,
                'sip_domain' => $server->sip_domain,
                'simulated' => $server->driver === 'simulator',
            ] : null,
            'recentCalls' => $extension ? CallRows::map(
                Call::where(fn ($q) => $q->where('from_extension_id', $extension->id)->orWhere('to_extension_id', $extension->id))
                    ->with(['fromExtension:id,name,number', 'toExtension:id,name,number'])
                    ->latest('started_at')->limit(15)->get()
            ) : [],
            'contacts' => $request->user()->can(Permission::CONTACTS_VIEW)
                ? Contact::orderBy('name')->limit(500)->get(['id', 'name', 'company', 'phone', 'mobile'])
                : [],
            'dial' => preg_match('/^\+?[\d*#]{1,20}$/', (string) $request->query('dial')) ? $request->query('dial') : null,
        ]);
    }

    public function credentials(Request $request): JsonResponse
    {
        Gate::authorize(Permission::WEBPHONE_USE);

        $extension = $request->user()->extension;
        $server = $this->tenant->get()->pbxServer()->first();

        abort_unless($extension && $extension->isActive() && $extension->webrtc_enabled, 403, 'Your extension is not enabled for the web phone.');
        abort_unless($server && $server->wss_url && $server->sip_domain, 409, 'The PBX server has no WebSocket (WSS) address configured.');

        Audit::record('webphone.connected', $extension);

        return response()->json([
            'username' => $extension->sip_username,
            'password' => $extension->sip_password,
            'domain' => $server->sip_domain,
            'wss_url' => $server->wss_url,
            'display_name' => $extension->caller_id_name ?: $extension->name,
        ])->header('Cache-Control', 'no-store');
    }
}
