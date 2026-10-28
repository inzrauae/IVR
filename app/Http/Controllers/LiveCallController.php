<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Call;
use App\Pbx\Exceptions\PbxException;
use App\Pbx\PbxManager;
use App\Support\Audit;
use App\Support\CallRows;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LiveCallController extends Controller
{
    public const SPY_MODES = ['listen', 'whisper', 'barge'];

    public function __construct(private readonly Tenant $tenant, private readonly PbxManager $pbx) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::CALLS_VIEW_LIVE);

        $calls = Call::live()->with(['fromExtension:id,name,number', 'toExtension:id,name,number', 'didNumber:id,number'])->latest('started_at')->get();
        $today = Call::where('started_at', '>=', now()->startOfDay());

        return Inertia::render('live-calls/Index', [
            'calls' => CallRows::map($calls),
            'stats' => [
                'live' => $calls->count(),
                'connected' => $calls->where('status', Call::STATUS_CONNECTED)->count(),
                'ringing' => $calls->where('status', Call::STATUS_RINGING)->count(),
                'today' => (clone $today)->count(),
                'missed_today' => (clone $today)->where('status', Call::STATUS_MISSED)->count(),
            ],
            'myExtension' => $request->user()->extension?->only(['id', 'number', 'name']),
            'can' => ['manage' => $request->user()->can(Permission::CALLS_MANAGE_LIVE)],
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    public function hangup(int $call): RedirectResponse
    {
        Gate::authorize(Permission::CALLS_MANAGE_LIVE);

        $record = Call::live()->findOrFail($call);
        $server = $record->pbxServer;

        try {
            abort_unless($server, 409, 'This call has no PBX server.');
            $driver = $this->pbx->driver($server);
            foreach ($record->active_channels as $channelId) {
                $driver->hangupChannel($channelId);
            }
        } catch (PbxException $e) {
            return back()->with('error', 'Could not hang up: '.$e->getMessage());
        }

        Audit::record('call.hung_up', $record, ['from' => $record->from_number, 'to' => $record->to_number]);

        return back()->with('success', 'Call ended.');
    }

    /**
     * Call the supervisor's own extension and connect it to the call to listen, whisper or barge.
     */
    public function spy(Request $request, int $call): RedirectResponse
    {
        Gate::authorize(Permission::CALLS_MANAGE_LIVE);

        $validated = $request->validate(['mode' => ['required', Rule::in(self::SPY_MODES)]]);
        $record = Call::live()->findOrFail($call);
        $extension = $request->user()->extension;

        if (! $extension || ! $extension->isActive()) {
            return back()->with('error', 'You need an active extension of your own to monitor calls.');
        }

        // Spy on the agent's leg, so a whisper reaches the agent and never the customer.
        $agent = $record->direction === Call::DIRECTION_INBOUND ? $record->toExtension : ($record->fromExtension ?? $record->toExtension);

        if (! $agent || ! $record->pbxServer) {
            return back()->with('error', 'Only calls on one of your extensions can be monitored.');
        }

        try {
            $this->pbx->driver($record->pbxServer)->spy($extension->sip_username, "PJSIP/{$agent->sip_username}", $validated['mode']);
        } catch (PbxException $e) {
            return back()->with('error', $e->getMessage());
        }

        Audit::record('call.monitored', $record, ['mode' => $validated['mode'], 'extension' => $extension->number]);

        return back()->with('success', "Calling your extension {$extension->number} to {$validated['mode']}.");
    }
}
