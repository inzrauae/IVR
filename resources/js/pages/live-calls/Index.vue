<script setup lang="ts">
import CallDirectionIcon from '@/components/CallDirectionIcon.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDuration, secondsSince } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import type { CallRow } from '@/types/calls';
import { Head, router } from '@inertiajs/vue3';
import { Ear, MessageSquareText, PhoneCall, PhoneMissed, PhoneOff, Radio, Users } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    calls: CallRow[];
    stats: { live: number; connected: number; ringing: number; today: number; missed_today: number };
    myExtension: { id: number; number: string; name: string } | null;
    can: { manage: boolean };
    serverTime: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Live Calls', href: '/live-calls' }];

// Durations tick every second; the list itself refreshes from the server every few seconds.
const clockOffset = new Date(props.serverTime).getTime() - Date.now();
const now = ref(Date.now() + clockOffset);
let tick: ReturnType<typeof setInterval> | undefined;
let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    tick = setInterval(() => (now.value = Date.now() + clockOffset), 1000);
    poll = setInterval(() => router.reload({ only: ['calls', 'stats'] }), 3000);
});
onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(poll);
});

const party = (number: string | null, name: string | null) => (name ? `${name} · ${number ?? ''}` : (number ?? 'Unknown'));

const ending = ref<CallRow | null>(null);
const hangup = () => {
    if (!ending.value) return;
    router.post(route('live-calls.hangup', { call: ending.value.id }), {}, { preserveScroll: true, onFinish: () => (ending.value = null) });
};

const spy = (call: CallRow, mode: 'listen' | 'whisper' | 'barge') =>
    router.post(route('live-calls.spy', { call: call.id }), { mode }, { preserveScroll: true });
</script>

<template>
    <Head title="Live Calls" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Live Calls" description="Calls in progress right now. Updates every few seconds.">
                <span class="inline-flex items-center gap-2 text-sm text-muted-foreground">
                    <span class="relative flex size-2.5">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                        <span class="relative inline-flex size-2.5 rounded-full bg-emerald-500" />
                    </span>
                    Live
                </span>
            </PageHeader>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Calls in progress"
                    :value="stats.live"
                    :icon="Radio"
                    :hint="`${stats.connected} connected · ${stats.ringing} ringing`"
                />
                <StatCard label="Connected" :value="stats.connected" :icon="PhoneCall" />
                <StatCard label="Calls today" :value="stats.today" :icon="Users" />
                <StatCard label="Missed today" :value="stats.missed_today" :icon="PhoneMissed" />
            </div>

            <Card>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">From</th>
                                <th class="px-4 py-3 font-medium">To</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Duration</th>
                                <th v-if="can.manage" class="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="call in calls" :key="call.id" class="hover:bg-muted/30">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <CallDirectionIcon :direction="call.direction" />
                                        <span class="font-medium">{{ party(call.from_number, call.from_name) }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    {{ party(call.to_number, call.to_name) }}
                                    <div v-if="call.did" class="text-xs text-muted-foreground">via +{{ call.did }}</div>
                                </td>
                                <td class="px-4 py-3"><StatusBadge :status="call.status" /></td>
                                <td class="px-4 py-3 font-mono tabular-nums">
                                    {{ formatDuration(secondsSince(call.answered_at ?? call.started_at, now)) }}
                                    <div class="text-xs text-muted-foreground">{{ call.answered_at ? 'talking' : 'ringing' }}</div>
                                </td>
                                <td v-if="can.manage" class="px-4 py-3">
                                    <div class="flex justify-end gap-1">
                                        <template v-if="call.status !== 'ringing'">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                :disabled="!myExtension"
                                                :title="
                                                    myExtension ? 'Listen silently from your extension' : 'You need an extension to monitor calls'
                                                "
                                                @click="spy(call, 'listen')"
                                            >
                                                <Ear class="size-4" /> Listen
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                :disabled="!myExtension"
                                                title="Speak to the agent only"
                                                @click="spy(call, 'whisper')"
                                            >
                                                <MessageSquareText class="size-4" /> Whisper
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                :disabled="!myExtension"
                                                title="Join the call"
                                                @click="spy(call, 'barge')"
                                            >
                                                <Users class="size-4" /> Barge
                                            </Button>
                                        </template>
                                        <Button variant="ghost" size="sm" class="text-destructive" @click="ending = call">
                                            <PhoneOff class="size-4" /> End
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="calls.length === 0">
                                <td :colspan="can.manage ? 5 : 4" class="px-4 py-12 text-center text-muted-foreground">No calls in progress.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <p v-if="can.manage" class="text-xs text-muted-foreground">
                Listen, whisper and barge ring
                <template v-if="myExtension">your extension {{ myExtension.number }}</template>
                <template v-else>your own extension (ask an admin to assign you one)</template>; answer it to join the call.
            </p>
        </div>

        <ConfirmDialog
            :open="ending !== null"
            title="End this call?"
            :description="ending ? `Hangs up ${party(ending.from_number, ending.from_name)} and ${party(ending.to_number, ending.to_name)}.` : ''"
            confirm-label="End call"
            @confirm="hangup"
            @cancel="ending = null"
        />
    </AppLayout>
</template>
