<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PbxServer;
use App\Pbx\PbxManager;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PbxServerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/PbxServers', [
            'servers' => PbxServer::withCount('organizations')->orderBy('name')->get()->map(fn (PbxServer $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'driver' => $s->driver,
                'region' => $s->region,
                'sip_domain' => $s->sip_domain,
                'ari_url' => $s->ari_url,
                'is_active' => $s->is_active,
                'status' => $s->status,
                'organizations_count' => $s->organizations_count,
                'last_health_check_at' => $s->last_health_check_at?->toIso8601String(),
                'last_health' => $s->last_health_payload,
            ]),
            'drivers' => PbxManager::availableDrivers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', Rule::in(PbxManager::availableDrivers())],
            'region' => ['nullable', 'string', 'max:100'],
            'sip_domain' => ['nullable', 'string', 'max:255'],
            'ari_url' => ['required_if:driver,asterisk', 'nullable', 'url:http,https', 'max:255'],
            'ari_username' => ['required_if:driver,asterisk', 'nullable', 'string', 'max:255'],
            'ari_password' => ['required_if:driver,asterisk', 'nullable', 'string', 'max:255'],
            'ami_host' => ['nullable', 'string', 'max:255'],
            'ami_port' => ['nullable', 'integer', 'between:1,65535'],
            'ami_username' => ['nullable', 'string', 'max:255'],
            'ami_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $server = PbxServer::create(array_filter($validated, fn ($v) => $v !== null));
        Audit::record('pbx_server.created', $server, ['driver' => $server->driver]);

        return back()->with('success', "{$server->name} has been added. Run a health check to verify the connection.");
    }

    public function checkHealth(PbxServer $pbxServer, PbxManager $pbx): RedirectResponse
    {
        try {
            $health = $pbx->checkHealth($pbxServer);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', "Health check failed: {$e->getMessage()}");
        }

        return $health->online
            ? back()->with('success', "{$pbxServer->name} is online".($health->version && $pbxServer->driver === 'asterisk' ? " (Asterisk {$health->version})." : '.'))
            : back()->with('error', "{$pbxServer->name} is offline: {$health->error}");
    }
}
