<script setup lang="ts">
import CallDirectionIcon from '@/components/CallDirectionIcon.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatDuration, formatTime } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import type { CallRow } from '@/types/calls';
import { Head } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    call: CallRow;
    events: { id: number; type: string; channel: string | null; occurred_at: string }[];
    recordings: { id: number; duration: number | null; created_at: string }[];
    can: { download: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Call History', href: '/call-history' },
    { title: `Call #${props.call.id}`, href: `/call-history/${props.call.id}` },
];

const ring = computed(() =>
    props.call.answered_at ? (new Date(props.call.answered_at).getTime() - new Date(props.call.started_at).getTime()) / 1000 : null,
);

const describe: Record<string, string> = {
    Newchannel: 'Channel created',
    DialBegin: 'Ringing',
    Newstate: 'State changed',
    BridgeEnter: 'Connected',
    Hold: 'Put on hold',
    Unhold: 'Taken off hold',
    Hangup: 'Hung up',
    Reconciled: 'Closed after reconnect',
};
</script>

<template>
    <Head :title="`Call #${call.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center gap-3">
                <CallDirectionIcon :direction="call.direction" :status="call.status" class="size-6" />
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ call.from_name ?? call.from_number ?? 'Unknown' }} → {{ call.to_name ?? call.to_number ?? 'Unknown' }}
                </h1>
                <StatusBadge :status="call.status" />
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader><CardTitle class="text-base">Details</CardTitle></CardHeader>
                    <CardContent>
                        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-muted-foreground">Direction</dt>
                                <dd class="capitalize">{{ call.direction }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Started</dt>
                                <dd>{{ formatDateTime(call.started_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">From</dt>
                                <dd>
                                    {{ call.from_number ?? '—' }}
                                    <span v-if="call.from_name" class="text-muted-foreground">({{ call.from_name }})</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">To</dt>
                                <dd>
                                    {{ call.to_number ?? '—' }} <span v-if="call.to_name" class="text-muted-foreground">({{ call.to_name }})</span>
                                </dd>
                            </div>
                            <div v-if="call.did">
                                <dt class="text-muted-foreground">Dialled number</dt>
                                <dd>+{{ call.did }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Ring time</dt>
                                <dd class="font-mono">{{ formatDuration(ring) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Talk time</dt>
                                <dd class="font-mono">{{ formatDuration(call.talk_time) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Total duration</dt>
                                <dd class="font-mono">{{ formatDuration(call.duration) }}</dd>
                            </div>
                            <div v-if="call.hangup_cause">
                                <dt class="text-muted-foreground">Hangup cause</dt>
                                <dd>{{ call.hangup_cause }}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle class="text-base">Timeline</CardTitle></CardHeader>
                    <CardContent>
                        <ol class="relative space-y-3 border-l pl-4 text-sm">
                            <li v-for="event in events" :key="event.id">
                                <span class="absolute -left-1.5 mt-1.5 size-3 rounded-full border bg-background" />
                                <div class="font-medium">{{ describe[event.type] ?? event.type }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ formatTime(event.occurred_at) }}<template v-if="event.channel"> · {{ event.channel }}</template>
                                </div>
                            </li>
                            <li v-if="!events.length" class="text-muted-foreground">No events recorded.</li>
                        </ol>
                    </CardContent>
                </Card>
            </div>

            <Card v-if="recordings.length">
                <CardHeader><CardTitle class="text-base">Recording</CardTitle></CardHeader>
                <CardContent class="space-y-3">
                    <div v-for="rec in recordings" :key="rec.id" class="flex flex-wrap items-center gap-3">
                        <audio controls preload="none" class="h-10 flex-1" :src="route('recordings.play', { recording: rec.id })" />
                        <Button v-if="can.download" variant="outline" size="sm" as="a" :href="route('recordings.download', { recording: rec.id })">
                            <Download class="size-4" /> Download
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
