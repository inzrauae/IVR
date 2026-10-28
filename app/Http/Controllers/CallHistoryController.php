<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Call;
use App\Models\Extension;
use App\Support\CallRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::CALL_HISTORY_VIEW);

        $filters = $this->filters($request);
        $calls = $this->query($request, $filters)
            ->with(['fromExtension:id,name,number', 'toExtension:id,name,number', 'didNumber:id,number', 'recordings:id,call_id'])
            ->latest('started_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('call-history/Index', [
            'calls' => [...$calls->toArray(), 'data' => CallRows::map($calls->items())],
            'filters' => $filters,
            'extensions' => Extension::orderBy('number')->get(['id', 'number', 'name']),
            'can' => [
                'recordings' => $request->user()->can(Permission::RECORDINGS_VIEW),
                'export' => $request->user()->can(Permission::REPORTS_EXPORT),
            ],
        ]);
    }

    public function show(Request $request, int $call): Response
    {
        Gate::authorize(Permission::CALL_HISTORY_VIEW);

        $record = Call::visibleTo($request->user())
            ->with(['fromExtension:id,name,number', 'toExtension:id,name,number', 'didNumber:id,number', 'recordings'])
            ->findOrFail($call);

        return Inertia::render('call-history/Show', [
            'call' => CallRows::map([$record])[0],
            'events' => $record->events()->get(['id', 'type', 'channel', 'occurred_at']),
            'recordings' => $request->user()->can(Permission::RECORDINGS_VIEW)
                ? $record->recordings->map(fn ($r) => ['id' => $r->id, 'duration' => $r->duration, 'created_at' => $r->created_at->toIso8601String()])
                : [],
            'can' => ['download' => $request->user()->can(Permission::RECORDINGS_DOWNLOAD)],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize(Permission::REPORTS_EXPORT);

        $query = $this->query($request, $this->filters($request))->with(['fromExtension:id,name,number', 'toExtension:id,name,number'])->latest('started_at');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Started', 'Direction', 'Status', 'From', 'From name', 'To', 'To name', 'Ring (s)', 'Talk (s)', 'Duration (s)', 'Hangup cause']);

            $query->chunk(500, function ($calls) use ($out) {
                foreach (CallRows::map($calls) as $row) {
                    $ring = $row['answered_at'] ? strtotime($row['answered_at']) - strtotime($row['started_at']) : null;
                    fputcsv($out, [$row['started_at'], $row['direction'], $row['status'], $row['from_number'], $row['from_name'], $row['to_number'], $row['to_name'], $ring, $row['talk_time'], $row['duration'], $row['hangup_cause']]);
                }
            });

            fclose($out);
        }, 'calls-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{search: ?string, direction: ?string, status: ?string, extension: ?int, from: ?string, to: ?string}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', Rule::in([Call::DIRECTION_INBOUND, Call::DIRECTION_OUTBOUND, Call::DIRECTION_INTERNAL])],
            'status' => ['nullable', Rule::in([Call::STATUS_COMPLETED, Call::STATUS_MISSED, Call::STATUS_FAILED, 'live'])],
            'extension' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            'search' => $validated['search'] ?? null,
            'direction' => $validated['direction'] ?? null,
            'status' => $validated['status'] ?? null,
            'extension' => isset($validated['extension']) ? (int) $validated['extension'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Call>
     */
    private function query(Request $request, array $filters): Builder
    {
        $tz = $request->user()->organization->timezone ?: 'UTC';

        return Call::visibleTo($request->user())
            ->when($filters['search'], fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('from_number', 'like', "%{$s}%")
                ->orWhere('to_number', 'like', "%{$s}%")
                ->orWhere('from_name', 'like', "%{$s}%")))
            ->when($filters['direction'], fn ($q, $d) => $q->where('direction', $d))
            ->when($filters['status'], fn ($q, $s) => $s === 'live' ? $q->live() : $q->where('status', $s))
            ->when($filters['extension'], fn ($q, $id) => $q->where(fn ($q) => $q->where('from_extension_id', $id)->orWhere('to_extension_id', $id)))
            ->when($filters['from'], fn ($q, $d) => $q->where('started_at', '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'], fn ($q, $d) => $q->where('started_at', '<=', Carbon::parse($d, $tz)->endOfDay()->utc()));
    }
}
