<script setup lang="ts">
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { humanizeAction } from '@/lib/icons';
import { type AuditEntry, type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Activity, Clock, Headset, PhoneCall, PhoneIncoming, PhoneMissed, PhoneOutgoing, Server, Users } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    plan: { name: string; status: string; trial_ends_at: string | null; price: string; currency: string } | null;
    seats: { used: number; limit: number | null };
    pbx: { name: string; status: string; checked_at: string | null } | null;
    recentActivity: AuditEntry[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const page = usePage<SharedData>();

const trialDaysLeft = computed(() => {
    if (!props.plan?.trial_ends_at) return null;
    return Math.max(0, Math.ceil((new Date(props.plan.trial_ends_at).getTime() - Date.now()) / 86_400_000));
});

// Call metrics come from CDRs and AMI events, which arrive with the live-call phase.
const callWidgets = [
    { label: 'Active calls', icon: PhoneCall },
    { label: 'Incoming today', icon: PhoneIncoming },
    { label: 'Outgoing today', icon: PhoneOutgoing },
    { label: 'Missed today', icon: PhoneMissed },
    { label: 'Available agents', icon: Headset },
    { label: 'Avg. wait time', icon: Clock },
];

const formatTime = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Welcome back, {{ page.props.auth.user.name.split(' ')[0] }}</h1>
                    <p class="text-sm text-muted-foreground">{{ page.props.organization?.name }} · communications overview</p>
                </div>
                <StatusBadge :status="page.props.organization?.status" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Plan" :value="plan?.name ?? 'No active plan'" :icon="Activity" :hint="trialDaysLeft !== null ? `Trial · ${trialDaysLeft} days left` : plan?.status" />
                <StatCard label="Team members" :value="seats.limit ? `${seats.used} / ${seats.limit}` : seats.used" :icon="Users" hint="Seats used on your plan" />
                <StatCard label="PBX server" :value="pbx?.name ?? 'Not assigned'" :icon="Server" :hint="pbx ? `Status: ${pbx.status}` : 'Assigned by your provider'" />
                <StatCard label="Registered extensions" :value="null" :icon="Headset" hint="Available once extensions ship" />
            </div>

            <Card>
                <CardHeader class="flex-row items-start justify-between space-y-0">
                    <div>
                        <CardTitle class="text-base">Call activity</CardTitle>
                        <CardDescription>Realtime metrics from your PBX</CardDescription>
                    </div>
                    <span class="rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground">Not yet connected</span>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <div v-for="w in callWidgets" :key="w.label" class="rounded-lg border border-dashed p-3">
                            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                <component :is="w.icon" class="size-3.5" />
                                {{ w.label }}
                            </div>
                            <p class="mt-2 text-xl font-semibold text-muted-foreground">—</p>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-muted-foreground">
                        Call metrics are read from real call detail records and PBX events. They will appear here once live call synchronization
                        (Phase 10) is enabled — no sample data is shown in the meantime.
                    </p>
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
