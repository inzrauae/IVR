<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Jobs\PushRouting;
use App\Models\Call;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Pbx\Routing\Destination;
use App\Pbx\Routing\NumberPlan;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Queue definitions and members are read by Asterisk realtime from the asterisk_queues and
 * asterisk_queue_members views, so membership changes apply without a push; the number,
 * maximum wait and fallback travel with the routing globals.
 */
class QueueController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::QUEUES_VIEW);

        $queues = CallQueue::with('members:id,number,name,registered')->orderBy('number')->get();
        $today = Call::where('started_at', '>=', now()->startOfDay())->whereIn('to_number', $queues->pluck('number'))
            ->selectRaw("to_number, count(*) as total, sum(case when status = 'completed' then 1 else 0 end) as answered, avg(case when answered_at is not null then talk_time end) as avg_talk")
            ->groupBy('to_number')->get()->keyBy('to_number');

        return Inertia::render('queues/Index', [
            'queues' => $queues->map(fn (CallQueue $q) => [
                ...$q->only(['id', 'number', 'name', 'strategy', 'agent_timeout', 'wrapup_time', 'max_wait', 'fallback_destination']),
                'members' => $q->members->map(fn ($m) => ['id' => $m->id, 'number' => $m->number, 'name' => $m->name, 'registered' => $m->registered, 'penalty' => $m->pivot->penalty, 'paused' => (bool) $m->pivot->paused]),
                'today' => [
                    'calls' => (int) ($today[$q->number]->total ?? 0),
                    'answered' => (int) ($today[$q->number]->answered ?? 0),
                    'avg_talk' => isset($today[$q->number]) ? (int) round((float) $today[$q->number]->avg_talk) : null,
                ],
            ]),
            'extensions' => Extension::orderBy('number')->get(['id', 'number', 'name']),
            'strategies' => CallQueue::STRATEGIES,
            'destinations' => Destination::options(),
            'nextNumber' => NumberPlan::next(700),
            'can' => ['manage' => $request->user()->can(Permission::QUEUES_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::QUEUES_MANAGE);

        $validated = $this->validated($request);
        $queue = DB::transaction(function () use ($validated) {
            $queue = CallQueue::create($validated);
            $queue->members()->sync($this->memberSync($validated['members']));

            return $queue;
        });

        PushRouting::forCurrentTenant();
        Audit::record('queue.created', $queue, ['name' => $queue->name]);

        return back()->with('success', "{$queue->name} created. Dial {$queue->number} to reach it.");
    }

    public function update(Request $request, int $queue): RedirectResponse
    {
        Gate::authorize(Permission::QUEUES_MANAGE);

        $record = CallQueue::findOrFail($queue);
        $validated = $this->validated($request, $record);

        DB::transaction(function () use ($record, $validated) {
            $record->update($validated);
            $record->members()->sync($this->memberSync($validated['members']));
        });

        PushRouting::forCurrentTenant();
        Audit::record('queue.updated', $record, ['name' => $record->name]);

        return back()->with('success', "{$record->name} saved.");
    }

    /**
     * Pause or resume one agent (they stay a member but are not offered calls).
     */
    public function pause(Request $request, int $queue, int $extension): RedirectResponse
    {
        Gate::authorize(Permission::QUEUES_MANAGE);

        $record = CallQueue::findOrFail($queue);
        $member = $record->members()->whereKey($extension)->firstOrFail();
        $paused = $request->boolean('paused');
        $record->members()->updateExistingPivot($member->id, ['paused' => $paused]);
        Audit::record($paused ? 'queue.agent_paused' : 'queue.agent_resumed', $record, ['extension' => $member->number]);

        return back()->with('success', "{$member->number} ".($paused ? 'paused' : 'resumed')." in {$record->name}.");
    }

    public function destroy(int $queue): RedirectResponse
    {
        Gate::authorize(Permission::QUEUES_MANAGE);

        $record = CallQueue::findOrFail($queue);
        Audit::record('queue.deleted', $record, ['name' => $record->name]);
        $record->delete();
        PushRouting::forCurrentTenant();

        return back()->with('success', "{$record->name} deleted.");
    }

    /**
     * @param  list<array{id: int, penalty?: int}>  $members
     * @return array<int, array{penalty: int}>
     */
    private function memberSync(array $members): array
    {
        return collect($members)->mapWithKeys(fn ($m) => [(int) $m['id'] => ['penalty' => (int) ($m['penalty'] ?? 0)]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CallQueue $current = null): array
    {
        $validated = $request->validate([
            'number' => NumberPlan::rules($current),
            'name' => ['required', 'string', 'max:100'],
            'strategy' => ['required', Rule::in(array_keys(CallQueue::STRATEGIES))],
            'agent_timeout' => ['required', 'integer', 'between:5,120'],
            'wrapup_time' => ['required', 'integer', 'between:0,300'],
            'max_wait' => ['required', 'integer', 'between:30,3600'],
            'members' => ['array', 'max:100'],
            'members.*.id' => ['required', 'integer', 'distinct', Rule::exists(Extension::class, 'id')->where('organization_id', $this->tenant->id())],
            'members.*.penalty' => ['nullable', 'integer', 'between:0,9'],
            'fallback_destination' => ['nullable', 'string', Destination::rule()],
        ]);

        if ($current && ($validated['fallback_destination'] ?? null) === "queue:{$current->id}") {
            $validated['fallback_destination'] = null;
        }

        return [...$validated, 'members' => $validated['members'] ?? []];
    }
}
