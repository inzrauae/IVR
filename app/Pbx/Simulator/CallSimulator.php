<?php

namespace App\Pbx\Simulator;

use App\Models\Call;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\PbxServer;
use App\Models\SipTrunk;
use App\Pbx\Calls\CallEventProcessor;
use LogicException;

/**
 * Emits the same AMI events Asterisk would for basic extension-to-extension calls, through
 * the real CallEventProcessor. Development and tests only.
 */
class CallSimulator
{
    public function __construct(private readonly CallEventProcessor $processor)
    {
        if (app()->isProduction()) {
            throw new LogicException('The call simulator cannot be used in production.');
        }
    }

    /**
     * Start ringing a call from an extension to a number. Returns the call's linked id.
     */
    public function dial(PbxServer $server, Extension $from, string $to): string
    {
        $linked = $this->uniqueId();
        $caller = $this->channel($from);

        $this->emit($server, [
            'Event' => 'Newchannel', 'Channel' => $caller, 'ChannelStateDesc' => 'Ring',
            'CallerIDNum' => $from->number, 'CallerIDName' => $from->name, 'Exten' => $to,
            'Context' => 'cloudpbx-internal', 'Uniqueid' => $linked, 'Linkedid' => $linked,
        ]);

        $target = Extension::withoutGlobalScopes()->where('organization_id', $from->organization_id)->where('number', $to)->first();

        if ($target) {
            $callee = $this->channel($target);
            $calleeId = $this->uniqueId();

            $this->emit($server, [
                'Event' => 'Newchannel', 'Channel' => $callee, 'ChannelStateDesc' => 'Down',
                'CallerIDNum' => $target->number, 'Exten' => $to, 'Uniqueid' => $calleeId, 'Linkedid' => $linked,
            ]);
            $this->emit($server, [
                'Event' => 'DialBegin', 'Channel' => $caller, 'Uniqueid' => $linked, 'Linkedid' => $linked,
                'DestChannel' => $callee, 'DestUniqueid' => $calleeId, 'DestLinkedid' => $linked, 'DialString' => $target->sip_username,
            ]);
            $this->emit($server, ['Event' => 'Newstate', 'Channel' => $callee, 'ChannelStateDesc' => 'Ringing', 'Uniqueid' => $calleeId, 'Linkedid' => $linked]);
        } elseif (preg_match('/^\+?\d{6,}$/', $to)) {
            // External number: the call leaves on a carrier trunk.
            $trunk = 'PJSIP/'.SipTrunk::endpointIdFor($from->organization_id, 0).'-'.bin2hex(random_bytes(4));
            $trunkId = $this->uniqueId();

            $this->emit($server, ['Event' => 'Newchannel', 'Channel' => $trunk, 'ChannelStateDesc' => 'Down', 'Exten' => $to, 'Uniqueid' => $trunkId, 'Linkedid' => $linked]);
            $this->emit($server, ['Event' => 'DialBegin', 'Channel' => $caller, 'Uniqueid' => $linked, 'Linkedid' => $linked, 'DestChannel' => $trunk, 'DestUniqueid' => $trunkId, 'DestLinkedid' => $linked]);
        }

        return $linked;
    }

    /**
     * A carrier call to a DID, arriving on the DID's trunk and ringing $answerer if given.
     */
    public function inbound(PbxServer $server, DidNumber $did, string $callerNumber, ?Extension $answerer = null): string
    {
        $linked = $this->uniqueId();
        $trunkChannel = 'PJSIP/'.SipTrunk::endpointIdFor($did->organization_id, (int) $did->sip_trunk_id).'-'.bin2hex(random_bytes(4));

        $this->emit($server, [
            'Event' => 'Newchannel', 'Channel' => $trunkChannel, 'ChannelStateDesc' => 'Ring',
            'CallerIDNum' => $callerNumber, 'CallerIDName' => '', 'Exten' => $did->number,
            'Context' => 'cloudpbx-inbound', 'Uniqueid' => $linked, 'Linkedid' => $linked,
        ]);

        if ($answerer) {
            $callee = $this->channel($answerer);
            $calleeId = $this->uniqueId();

            $this->emit($server, ['Event' => 'Newchannel', 'Channel' => $callee, 'ChannelStateDesc' => 'Down', 'Exten' => $answerer->number, 'Uniqueid' => $calleeId, 'Linkedid' => $linked]);
            $this->emit($server, ['Event' => 'DialBegin', 'Channel' => $trunkChannel, 'Uniqueid' => $linked, 'Linkedid' => $linked, 'DestChannel' => $callee, 'DestUniqueid' => $calleeId, 'DestLinkedid' => $linked]);
            $this->emit($server, ['Event' => 'Newstate', 'Channel' => $callee, 'ChannelStateDesc' => 'Ringing', 'Uniqueid' => $calleeId, 'Linkedid' => $linked]);
        }

        return $linked;
    }

    /**
     * The dialplan's announcement that a call recording was saved.
     */
    public function recorded(PbxServer $server, string $linkedId): void
    {
        $call = $this->call($server, $linkedId);

        $this->emit($server, [
            'Event' => 'UserEvent', 'UserEvent' => 'CloudPBXRecording', 'Org' => (string) $call->organization_id,
            'Linkedid' => $linkedId, 'Name' => 'cpbx-o'.$call->organization_id.'-'.str_replace('.', '-', $linkedId),
        ]);
    }

    /**
     * The dialplan's announcement that a caller left a voicemail.
     */
    public function voicemail(PbxServer $server, Extension $mailbox, string $callerNumber, int $seconds = 12): void
    {
        $this->emit($server, [
            'Event' => 'UserEvent', 'UserEvent' => 'CloudPBXVoicemail', 'Org' => (string) $mailbox->organization_id,
            'Mailbox' => $mailbox->sip_username, 'Name' => 'cpbx-vm-'.$mailbox->sip_username.'-'.str_replace('.', '-', $this->uniqueId()),
            'CallerNum' => $callerNumber, 'CallerName' => '', 'Duration' => (string) $seconds,
        ]);
    }

    public function answer(PbxServer $server, string $linkedId): void
    {
        $call = $this->call($server, $linkedId);
        $callee = collect($call->active_channels)->first(fn ($id) => $id !== $linkedId);

        if (! $callee) {
            return;
        }

        $this->emit($server, ['Event' => 'Newstate', 'ChannelStateDesc' => 'Up', 'Uniqueid' => $callee, 'Linkedid' => $linkedId, 'Channel' => $this->channelName($call, $callee)]);
        $this->emit($server, ['Event' => 'BridgeEnter', 'BridgeNumChannels' => '2', 'Uniqueid' => $callee, 'Linkedid' => $linkedId]);
    }

    public function hold(PbxServer $server, string $linkedId, bool $hold = true): void
    {
        $this->emit($server, ['Event' => $hold ? 'Hold' : 'Unhold', 'Uniqueid' => $linkedId, 'Linkedid' => $linkedId]);
    }

    /**
     * Hang up the whole call. $cause is a Q.850 code (16 normal, 17 busy, 19 no answer).
     */
    public function hangup(PbxServer $server, string $linkedId, string $cause = '16'): void
    {
        $call = $this->call($server, $linkedId);

        foreach ($call->active_channels as $id) {
            $this->emit($server, [
                'Event' => 'Hangup', 'Uniqueid' => $id, 'Linkedid' => $linkedId,
                'Cause' => $cause, 'Cause-txt' => $this->causeText($cause),
            ]);
        }
    }

    /**
     * Hanging up one leg of a two-party call ends the call, as a bridge would in Asterisk.
     */
    public function hangupChannel(PbxServer $server, string $channelId): void
    {
        $call = Call::withoutGlobalScopes()->where('pbx_server_id', $server->id)->live()->get()
            ->first(fn (Call $c) => in_array($channelId, $c->active_channels, true));

        if ($call) {
            $this->hangup($server, $call->linked_id);
        }
    }

    public function register(Extension $extension, bool $registered = true): void
    {
        $this->processor->handle(new PbxServer, [
            'Event' => 'ContactStatus',
            'EndpointName' => $extension->sip_username,
            'AOR' => $extension->sip_username,
            'URI' => "sip:{$extension->sip_username}@127.0.0.1:5060",
            'ContactStatus' => $registered ? 'Reachable' : 'Removed',
            'UserAgent' => 'CloudPBX Simulator',
        ]);
    }

    /**
     * @param  array<string, string>  $event
     */
    private function emit(PbxServer $server, array $event): void
    {
        $this->processor->handle($server, $event);
    }

    private function call(PbxServer $server, string $linkedId): Call
    {
        return Call::withoutGlobalScopes()->where('pbx_server_id', $server->id)->where('linked_id', $linkedId)->firstOrFail();
    }

    private function channel(Extension $extension): string
    {
        return 'PJSIP/'.$extension->sip_username.'-'.bin2hex(random_bytes(4));
    }

    private function channelName(Call $call, string $uniqueId): ?string
    {
        return $call->events()->where('data->Uniqueid', $uniqueId)->value('channel');
    }

    private function uniqueId(): string
    {
        return sprintf('%d.%d', time(), random_int(1, 999_999));
    }

    private function causeText(string $cause): string
    {
        return match ($cause) {
            '16' => 'Normal Clearing',
            '17' => 'User busy',
            '19' => 'No user responding',
            '21' => 'Call Rejected',
            '34' => 'No circuit/channel available',
            default => 'Unknown',
        };
    }
}
