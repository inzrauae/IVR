<?php

namespace App\Pbx\Calls;

use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\PbxServer;
use Illuminate\Support\Arr;

/**
 * Turns Asterisk AMI events into Call records. Fed by the AMI listener in production and by
 * the call simulator in development, so both exercise the same code.
 *
 * Every channel of a call shares a Linkedid; the call ends when its last channel hangs up.
 */
class CallEventProcessor
{
    /** Q.850 causes that mean "nobody picked up" rather than a technical failure. */
    private const UNANSWERED_CAUSES = ['16', '17', '18', '19', '21'];

    /** AMI headers not worth storing on call events. */
    private const NOISE = ['Privilege', 'SystemName', 'Timestamp', 'File', 'Line', 'Func', 'SequenceNumber', 'ActionID', 'Language', 'AccountCode'];

    /**
     * @param  array<string, string>  $event
     */
    public function handle(PbxServer $server, array $event): void
    {
        match ($event['Event'] ?? null) {
            'Newchannel' => $this->newChannel($server, $event),
            'DialBegin' => $this->dialBegin($server, $event),
            'Newstate' => $this->newState($server, $event),
            'BridgeEnter' => $this->bridgeEnter($server, $event),
            'Hold' => $this->hold($server, $event, true),
            'Unhold' => $this->hold($server, $event, false),
            'Hangup' => $this->hangup($server, $event),
            'ContactStatus' => $this->contactStatus($event),
            default => null,
        };
    }

    /**
     * End calls whose channels vanished while we were not listening (e.g. after a reconnect).
     *
     * @param  list<string>  $activeChannelIds
     */
    public function reconcile(PbxServer $server, array $activeChannelIds): int
    {
        $closed = 0;
        $active = array_flip($activeChannelIds);

        Call::withoutGlobalScopes()->where('pbx_server_id', $server->id)->live()->each(function (Call $call) use ($active, &$closed) {
            $remaining = array_values(array_filter($call->active_channels, fn ($id) => isset($active[$id])));

            if ($remaining === []) {
                $this->record($call, 'Reconciled', null, ['reason' => 'channels no longer present on PBX']);
                $this->finish($call, null, 'Ended while the event listener was disconnected');
                $closed++;
            } elseif ($remaining !== $call->active_channels) {
                $call->forceFill(['active_channels' => $remaining])->save();
            }
        });

        return $closed;
    }

    /**
     * @param  array<string, string>  $e
     */
    private function newChannel(PbxServer $server, array $e): void
    {
        $uniqueId = $e['Uniqueid'] ?? null;
        $linkedId = $e['Linkedid'] ?? $uniqueId;

        if (! $uniqueId) {
            return;
        }

        $call = $this->findCall($server, $linkedId);

        if (! $call) {
            // Only the originating leg starts a call; anything else belongs to a call we don't track.
            if ($uniqueId !== $linkedId || ! ($caller = $this->endpointFromChannel($e['Channel'] ?? ''))) {
                return;
            }

            $organization = Organization::find($caller['organization_id']);
            if (! $organization) {
                return;
            }

            $from = $this->extension($caller['organization_id'], $caller['number']);
            $dialled = $this->dialled($e);
            $target = $dialled ? $this->extension($organization->id, $dialled) : null;

            $call = Call::create([
                'organization_id' => $organization->id,
                'pbx_server_id' => $server->id,
                'linked_id' => $linkedId,
                'direction' => $target ? Call::DIRECTION_INTERNAL : Call::DIRECTION_OUTBOUND,
                'status' => Call::STATUS_RINGING,
                'from_number' => $caller['number'],
                'from_name' => $this->callerName($e) ?? $from?->name,
                'to_number' => $dialled,
                'from_extension_id' => $from?->id,
                'to_extension_id' => $target?->id,
                'active_channels' => [$uniqueId],
                'started_at' => now(),
            ]);

            $this->record($call, 'Newchannel', $e['Channel'] ?? null, $e);

            return;
        }

        $channels = $call->active_channels;
        if (! in_array($uniqueId, $channels, true)) {
            $channels[] = $uniqueId;
        }
        $call->active_channels = $channels;

        if ($uniqueId !== $linkedId) {
            $this->attachCallee($call, $e['Channel'] ?? '');
        }

        $call->save();
        $this->record($call, 'Newchannel', $e['Channel'] ?? null, $e);
    }

    /**
     * @param  array<string, string>  $e
     */
    private function dialBegin(PbxServer $server, array $e): void
    {
        $call = $this->findCall($server, $e['DestLinkedid'] ?? $e['Linkedid'] ?? '');

        if (! $call) {
            return;
        }

        $this->attachCallee($call, $e['DestChannel'] ?? '');
        $call->save();
        $this->record($call, 'DialBegin', $e['DestChannel'] ?? null, $e);
    }

    /**
     * @param  array<string, string>  $e
     */
    private function newState(PbxServer $server, array $e): void
    {
        $call = $this->findCall($server, $e['Linkedid'] ?? '');

        if (! $call) {
            return;
        }

        // The callee leg going "Up" is the moment the call is answered.
        if (($e['ChannelStateDesc'] ?? null) === 'Up' && ($e['Uniqueid'] ?? null) !== $call->linked_id) {
            $this->markAnswered($call);
        }

        $this->record($call, 'Newstate', $e['Channel'] ?? null, $e);
    }

    /**
     * @param  array<string, string>  $e
     */
    private function bridgeEnter(PbxServer $server, array $e): void
    {
        $call = $this->findCall($server, $e['Linkedid'] ?? '');

        if (! $call) {
            return;
        }

        if ((int) ($e['BridgeNumChannels'] ?? 0) >= 2) {
            $this->markAnswered($call);
        }

        $this->record($call, 'BridgeEnter', $e['Channel'] ?? null, $e);
    }

    /**
     * @param  array<string, string>  $e
     */
    private function hold(PbxServer $server, array $e, bool $onHold): void
    {
        $call = $this->findCall($server, $e['Linkedid'] ?? '');

        if (! $call || ! $call->answered_at || ! $call->isLive()) {
            return;
        }

        $call->forceFill(['status' => $onHold ? Call::STATUS_ON_HOLD : Call::STATUS_CONNECTED])->save();
        $this->record($call, $onHold ? 'Hold' : 'Unhold', $e['Channel'] ?? null, $e);
    }

    /**
     * @param  array<string, string>  $e
     */
    private function hangup(PbxServer $server, array $e): void
    {
        $call = $this->findCall($server, $e['Linkedid'] ?? '');

        if (! $call) {
            return;
        }

        $this->record($call, 'Hangup', $e['Channel'] ?? null, $e);

        $remaining = array_values(array_diff($call->active_channels, [$e['Uniqueid'] ?? '']));
        $call->active_channels = $remaining;

        if ($remaining === [] && $call->isLive()) {
            $this->finish($call, $e['Cause'] ?? null, $e['Cause-txt'] ?? null);

            return;
        }

        $call->save();
    }

    /**
     * Registration changes for extensions.
     *
     * @param  array<string, string>  $e
     */
    private function contactStatus(array $e): void
    {
        $id = $e['EndpointName'] ?? $e['AOR'] ?? null;
        $parsed = $id ? Extension::parseSipUsername($id) : null;

        if (! $parsed) {
            return;
        }

        $extension = Extension::withoutGlobalScopes()->where('sip_username', $id)->first();

        if (! $extension) {
            return;
        }

        $status = $e['ContactStatus'] ?? '';
        $registered = in_array($status, ['Created', 'Reachable', 'Updated', 'NonQualified'], true);

        $extension->forceFill([
            'registered' => $registered,
            'registered_contact' => $registered ? Arr::get($e, 'URI') : null,
            'registered_user_agent' => $registered ? (Arr::get($e, 'UserAgent') ?: $extension->registered_user_agent) : null,
            'registration_changed_at' => now(),
        ])->save();
    }

    private function markAnswered(Call $call): void
    {
        if ($call->answered_at || ! $call->isLive()) {
            return;
        }

        $call->forceFill(['status' => Call::STATUS_CONNECTED, 'answered_at' => now()])->save();
    }

    private function finish(Call $call, ?string $cause, ?string $causeText): void
    {
        $ended = now();

        $status = match (true) {
            $call->answered_at !== null => Call::STATUS_COMPLETED,
            $cause === null || in_array($cause, self::UNANSWERED_CAUSES, true) => $call->direction === Call::DIRECTION_OUTBOUND ? Call::STATUS_FAILED : Call::STATUS_MISSED,
            default => Call::STATUS_FAILED,
        };

        $call->forceFill([
            'status' => $status,
            'active_channels' => [],
            'ended_at' => $ended,
            'hangup_cause' => $causeText ?? $cause,
            'duration' => max(0, (int) $call->started_at->diffInSeconds($ended)),
            'talk_time' => $call->answered_at ? max(0, (int) $call->answered_at->diffInSeconds($ended)) : 0,
        ])->save();
    }

    private function attachCallee(Call $call, string $channel): void
    {
        $callee = $this->endpointFromChannel($channel);

        if (! $callee || $callee['organization_id'] !== $call->organization_id || $call->to_extension_id) {
            return;
        }

        if ($extension = $this->extension($call->organization_id, $callee['number'])) {
            $call->to_extension_id = $extension->id;
            $call->to_number ??= $extension->number;
        }
    }

    private function findCall(PbxServer $server, string $linkedId): ?Call
    {
        if ($linkedId === '') {
            return null;
        }

        return Call::withoutGlobalScopes()
            ->where('pbx_server_id', $server->id)
            ->where('linked_id', $linkedId)
            ->first();
    }

    private function extension(int $organizationId, string $number): ?Extension
    {
        return Extension::withoutGlobalScopes()->where('organization_id', $organizationId)->where('number', $number)->first();
    }

    /**
     * "PJSIP/o12_101-0000002a" -> ['organization_id' => 12, 'number' => '101']
     *
     * @return array{organization_id: int, number: string}|null
     */
    private function endpointFromChannel(string $channel): ?array
    {
        return preg_match('#^PJSIP/(.+)-[0-9a-f]+$#i', $channel, $m) ? Extension::parseSipUsername($m[1]) : null;
    }

    /**
     * @param  array<string, string>  $e
     */
    private function dialled(array $e): ?string
    {
        $exten = $e['Exten'] ?? '';

        return $exten !== '' && $exten !== 's' ? $exten : null;
    }

    /**
     * @param  array<string, string>  $e
     */
    private function callerName(array $e): ?string
    {
        $name = $e['CallerIDName'] ?? '';

        return $name !== '' && $name !== '<unknown>' ? $name : null;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function record(Call $call, string $type, ?string $channel, array $data): void
    {
        CallEvent::create([
            'call_id' => $call->id,
            'type' => $type,
            'channel' => $channel,
            'data' => Arr::except($data, self::NOISE),
            'occurred_at' => now(),
        ]);
    }
}
