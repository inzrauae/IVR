<?php

namespace App\Support;

use App\Models\Call;
use App\Models\Contact;
use Illuminate\Support\Collection;

/**
 * The row shape call lists (live calls, history, extension page) share with the frontend.
 */
final class CallRows
{
    /**
     * @param  iterable<Call>  $calls
     * @return list<array<string, mixed>>
     */
    public static function map(iterable $calls): array
    {
        $calls = Collection::make($calls);
        $names = Contact::namesFor($calls->flatMap(fn (Call $c) => [$c->from_number, $c->to_number]));

        return $calls->map(fn (Call $call) => [
            'id' => $call->id,
            'direction' => $call->direction,
            'status' => $call->status,
            'from_number' => $call->from_number,
            'from_name' => $call->from_name ?? $call->fromExtension?->name ?? $names[$call->from_number] ?? null,
            'to_number' => $call->to_number,
            'to_name' => $call->toExtension?->name ?? $names[$call->to_number] ?? null,
            'did' => $call->didNumber?->number,
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration' => $call->duration,
            'talk_time' => $call->talk_time,
            'hangup_cause' => $call->hangup_cause,
            'has_recording' => $call->relationLoaded('recordings') ? $call->recordings->isNotEmpty() : null,
        ])->values()->all();
    }
}
