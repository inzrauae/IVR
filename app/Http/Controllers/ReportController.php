<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Call;
use App\Models\Extension;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Call analytics over a date range, computed from call records in the organization's timezone.
 */
class ReportController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::REPORTS_VIEW);

        [$from, $to, $tz] = $this->range($request);
        $calls = $this->calls($from, $to);

        $answered = $calls->whereNotNull('answered_at');
        $inbound = $calls->where('direction', Call::DIRECTION_INBOUND);
        $ringTimes = $answered->map(fn ($c) => $c->started_at->diffInSeconds($c->answered_at));

        // One bucket per day in range, so days without calls still show.
        $days = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'inbound' => 0, 'outbound' => 0, 'internal' => 0, 'missed' => 0];
        }
        foreach ($calls as $call) {
            $key = $call->started_at->copy()->setTimezone($tz)->toDateString();
            if (isset($days[$key])) {
                $row = $days[$key];
                $row[$call->direction]++;
                $row['missed'] += $call->status === Call::STATUS_MISSED ? 1 : 0;
                $days[$key] = $row;
            }
        }

        $hours = array_fill(0, 24, 0);
        foreach ($inbound as $call) {
            $hours[(int) $call->started_at->copy()->setTimezone($tz)->format('G')]++;
        }

        return Inertia::render('reports/Index', [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $tz],
            'summary' => [
                'total' => $calls->count(),
                'inbound' => $inbound->count(),
                'outbound' => $calls->where('direction', Call::DIRECTION_OUTBOUND)->count(),
                'internal' => $calls->where('direction', Call::DIRECTION_INTERNAL)->count(),
                'answered' => $answered->count(),
                'missed' => $calls->where('status', Call::STATUS_MISSED)->count(),
                'failed' => $calls->where('status', Call::STATUS_FAILED)->count(),
                'answer_rate' => $inbound->count() ? round($inbound->whereNotNull('answered_at')->count() / $inbound->count() * 100, 1) : null,
                'avg_talk' => $answered->count() ? (int) round($answered->avg('talk_time')) : null,
                'avg_ring' => $ringTimes->count() ? (int) round($ringTimes->avg()) : null,
                'total_talk' => (int) $answered->sum('talk_time'),
            ],
            'daily' => $days->values(),
            'hourly' => $hours,
            'extensions' => $this->byExtension($calls),
            'topCallers' => $inbound->whereNotNull('from_number')->groupBy('from_number')
                ->map(fn ($group, $number) => ['number' => $number, 'name' => $group->first()->from_name, 'calls' => $group->count(), 'missed' => $group->where('status', Call::STATUS_MISSED)->count()])
                ->sortByDesc('calls')->take(10)->values(),
            'can' => ['export' => $request->user()->can(Permission::REPORTS_EXPORT)],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize(Permission::REPORTS_EXPORT);

        [$from, $to] = $this->range($request);
        $rows = $this->byExtension($this->calls($from, $to));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Extension', 'Name', 'Calls', 'Answered', 'Missed', 'Outbound', 'Talk time (s)', 'Average talk (s)']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['number'], $row['name'], $row['calls'], $row['answered'], $row['missed'], $row['outbound'], $row['talk'], $row['avg_talk']]);
            }
            fclose($out);
        }, "extension-report-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tz = $this->tenant->get()->timezone ?: 'UTC';
        $to = isset($validated['to']) ? Carbon::parse($validated['to'], $tz) : now($tz);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'], $tz) : $to->copy()->subDays(6);

        // Cap the range so a report never loads years of calls into memory.
        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92);
        }

        return [$from->startOfDay(), $to->endOfDay(), $tz];
    }

    /**
     * @return Collection<int, Call>
     */
    private function calls(Carbon $from, Carbon $to): Collection
    {
        return Call::finished()
            ->whereBetween('started_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->get(['id', 'direction', 'status', 'from_number', 'from_name', 'from_extension_id', 'to_extension_id', 'started_at', 'answered_at', 'talk_time']);
    }

    /**
     * @param  Collection<int, Call>  $calls
     * @return list<array<string, mixed>>
     */
    private function byExtension(Collection $calls): array
    {
        return Extension::orderBy('number')->get(['id', 'number', 'name'])->map(function (Extension $ext) use ($calls) {
            $mine = $calls->filter(fn ($c) => $c->from_extension_id === $ext->id || $c->to_extension_id === $ext->id);
            $received = $mine->where('to_extension_id', $ext->id);
            $answered = $mine->whereNotNull('answered_at');

            return [
                'id' => $ext->id,
                'number' => $ext->number,
                'name' => $ext->name,
                'calls' => $mine->count(),
                'answered' => $answered->count(),
                'missed' => $received->where('status', Call::STATUS_MISSED)->count(),
                'outbound' => $mine->where('from_extension_id', $ext->id)->where('direction', Call::DIRECTION_OUTBOUND)->count(),
                'talk' => (int) $answered->sum('talk_time'),
                'avg_talk' => $answered->count() ? (int) round($answered->avg('talk_time')) : null,
            ];
        })->sortByDesc('calls')->values()->all();
    }
}
