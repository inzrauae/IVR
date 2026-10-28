<script setup lang="ts">
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDuration } from '@/lib/format';
import { humanizeAction } from '@/lib/icons';
import { type AuditEntry, type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Activity, ArrowUpRight, Clock, Headset, PhoneCall, PhoneIncoming, PhoneMissed, PhoneOutgoing, Server, Users } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';

const props = defineProps<{
    plan: { name: string; status: string; trial_ends_at: string | null; price: string; currency: string } | null;
    seats: { used: number; limit: number | null };
    extensions: { registered: number; total: number };
    calls: { live: number; inbound: number; outbound: number; missed: number; available: number; avg_ring: number | null };
    pbx: { name: string; status: string; checked_at: string | null } | null;
    recentActivity: AuditEntry[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const page = usePage<SharedData>();
const callTotal = computed(() => props.calls.inbound + props.calls.outbound);
const callBreakdown = computed(() => [
    { label: 'Incoming', value: props.calls.inbound, color: 'bg-blue-500' },
    { label: 'Outgoing', value: props.calls.outbound, color: 'bg-cyan-400' },
]);

const trialDaysLeft = computed(() => {
    if (!props.plan?.trial_ends_at) return null;
    return Math.max(0, Math.ceil((new Date(props.plan.trial_ends_at).getTime() - Date.now()) / 86_400_000));
});

const callWidgets = computed(() => [
    { label: 'Active calls', icon: PhoneCall, value: props.calls.live },
    { label: 'Incoming today', icon: PhoneIncoming, value: props.calls.inbound },
    { label: 'Outgoing today', icon: PhoneOutgoing, value: props.calls.outbound },
    { label: 'Missed today', icon: PhoneMissed, value: props.calls.missed },
    { label: 'Available agents', icon: Headset, value: props.calls.available },
    { label: 'Avg. ring time', icon: Clock, value: props.calls.avg_ring === null ? '—' : formatDuration(props.calls.avg_ring) },
]);

// Call figures change constantly; refresh just them.
let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => (poll = setInterval(() => router.reload({ only: ['calls', 'extensions'] }), 10000)));
onBeforeUnmount(() => clearInterval(poll));

const formatTime = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-6 p-4 md:p-8">
            <div class="dashboard-hero flex flex-wrap items-center justify-between gap-6 rounded-2xl border p-6 md:p-8">
                <div>
                    <p class="mb-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-primary">Workspace overview</p>
                    <h1 class="text-2xl font-semibold tracking-tight md:text-3xl">Welcome back, {{ page.props.auth.user.name.split(' ')[0] }}</h1>
                    <p class="mt-2 text-sm text-muted-foreground">A clear view of your team, calls, and connections.</p>
                </div>
                <div class="flex items-center gap-3">
                    <StatusBadge :status="page.props.organization?.status" />
                    <Link :href="route('live-calls.index')" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90">
                        <PhoneCall class="size-4" /> Live calls <ArrowUpRight class="size-4" />
                    </Link>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Plan"
                    :value="plan?.name ?? 'No active plan'"
                    :icon="Activity"
                    :hint="trialDaysLeft !== null ? `Trial · ${trialDaysLeft} days left` : plan?.status"
                />
                <StatCard
                    label="Team members"
                    :value="seats.limit ? `${seats.used} / ${seats.limit}` : seats.used"
                    :icon="Users"
                    hint="Seats used on your plan"
                />
                <StatCard
                    label="PBX server"
                    :value="pbx?.name ?? 'Not assigned'"
                    :icon="Server"
                    :hint="pbx ? `Status: ${pbx.status}` : 'Assigned by your provider'"
                />
                <StatCard
                    label="Registered extensions"
                    :value="extensions.total ? `${extensions.registered} / ${extensions.total}` : 0"
                    :icon="Headset"
                    :hint="extensions.total ? 'Devices currently registered' : 'No extensions created yet'"
                />
            </div>

            <Card>
                <CardHeader class="flex-row items-start justify-between space-y-0">
                    <div>
                        <CardTitle class="text-base">Call activity</CardTitle>
                        <CardDescription>Today, from your PBX · refreshes every 10 seconds</CardDescription>
                    </div>
                    <Link :href="route('live-calls.index')" class="text-sm text-muted-foreground hover:underline">Live calls →</Link>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <div v-for="w in callWidgets" :key="w.label" class="rounded-lg border p-3">
                            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                <component :is="w.icon" class="size-3.5" />
                                {{ w.label }}
                            </div>
                            <p class="mt-3 text-2xl font-semibold tabular-nums tracking-tight">{{ w.value }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Today's call mix</CardTitle>
                    <CardDescription>Incoming and outgoing call counts reported by your PBX</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="callTotal" class="flex h-3 overflow-hidden rounded-full bg-muted" role="img" :aria-label="`Incoming: ${calls.inbound}, outgoing: ${calls.outbound}`">
                        <div v-for="segment in callBreakdown" :key="segment.label" :class="segment.color" :style="{ width: `${segment.value / callTotal * 100}%` }" />
                    </div>
                    <div v-else class="rounded-lg border border-dashed bg-muted/30 p-5 text-center text-sm text-muted-foreground">No call activity recorded today. Your call mix will appear here as calls are processed.</div>
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div v-for="segment in callBreakdown" :key="segment.label" class="flex items-center gap-2 text-xs text-muted-foreground">
                            <span class="size-2 shrink-0 rounded-full" :class="segment.color" />
                            <span>{{ segment.label }} <strong class="ml-1 font-semibold tabular-nums text-foreground">{{ segment.value }}</strong></span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Recent activity</CardTitle>
                    <CardDescription>Audit trail for your organization</CardDescription>
                </CardHeader>
                <CardContent>
                    <ul v-if="recentActivity.length" class="divide-y">
                        <li v-for="entry in recentActivity" :key="entry.id" class="flex items-center justify-between gap-4 py-2.5 text-sm">
                            <span>
                                <span class="font-medium">{{ humanizeAction(entry.action) }}</span>
                                <span class="text-muted-foreground"> · {{ entry.user?.name ?? 'System' }}</span>
                            </span>
                            <time class="shrink-0 text-xs text-muted-foreground">{{ formatTime(entry.created_at) }}</time>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">No activity yet.</p>
                    <Link
                        v-if="page.props.auth.permissions.includes('users.view')"
                        :href="route('users.index')"
                        class="mt-4 inline-block text-sm font-medium text-primary hover:underline"
                    >
                        Manage your team →
                    </Link>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
