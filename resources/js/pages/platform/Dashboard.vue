<script setup lang="ts">
import StatCard from '@/components/StatCard.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { humanizeAction } from '@/lib/icons';
import { type AuditEntry } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Building2, CircleAlert, Server, TrendingUp, Users } from 'lucide-vue-next';

interface Package {
    id: number;
    name: string;
    slug: string;
    currency: string;
    monthly_price: string;
    max_users: number | null;
    max_extensions: number | null;
    max_concurrent_calls: number | null;
    trial_days: number;
    is_active: boolean;
    features_count: number;
}

const props = defineProps<{
    stats: {
        organizations: { total: number; active: number; trial: number; suspended: number };
        users: number;
        mrr: number;
        pbx: { total: number; online: number; offline: number };
    };
    currency: string;
    packages: Package[];
    recentActivity: AuditEntry[];
}>();

const money = (amount: number | string, currency = props.currency) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 0 }).format(Number(amount));

const formatTime = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Platform overview" />

    <AppLayout :breadcrumbs="[{ title: 'Platform', href: '/platform' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Platform overview</h1>
                <p class="text-sm text-muted-foreground">All organizations, subscriptions and PBX infrastructure</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Organizations"
                    :value="stats.organizations.total"
                    :icon="Building2"
                    :hint="`${stats.organizations.active} active · ${stats.organizations.trial} trial`"
                />
                <StatCard label="Suspended" :value="stats.organizations.suspended" :icon="CircleAlert" hint="Organizations without access" />
                <StatCard label="Monthly recurring revenue" :value="money(stats.mrr)" :icon="TrendingUp" hint="Paying subscriptions, trials excluded" />
                <StatCard
                    label="PBX servers"
                    :value="`${stats.pbx.online} / ${stats.pbx.total}`"
                    :icon="Server"
                    :hint="stats.pbx.offline ? `${stats.pbx.offline} offline` : 'Online / total'"
                />
            </div>

            <div class="grid gap-6 lg:grid-cols-5">
                <Card class="lg:col-span-3">
                    <CardHeader>
                        <CardTitle class="text-base">Packages</CardTitle>
                        <CardDescription>Plans available to organizations</CardDescription>
                    </CardHeader>
                    <CardContent class="overflow-x-auto p-0">
                        <table class="w-full text-sm">
                            <thead class="border-y bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">Package</th>
                                    <th class="px-4 py-2.5 font-medium">Monthly</th>
                                    <th class="px-4 py-2.5 font-medium">Users</th>
                                    <th class="px-4 py-2.5 font-medium">Concurrent calls</th>
                                    <th class="px-4 py-2.5 font-medium">Features</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="p in packages" :key="p.id">
                                    <td class="px-4 py-2.5 font-medium">{{ p.name }}</td>
                                    <td class="px-4 py-2.5 tabular-nums">{{ money(p.monthly_price, p.currency) }}</td>
                                    <td class="px-4 py-2.5 tabular-nums">{{ p.max_users ?? '∞' }}</td>
                                    <td class="px-4 py-2.5 tabular-nums">{{ p.max_concurrent_calls ?? '∞' }}</td>
                                    <td class="px-4 py-2.5 tabular-nums">{{ p.features_count }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="text-base">Recent activity</CardTitle>
                        <CardDescription>Across all organizations · {{ stats.users }} tenant users</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="recentActivity.length" class="divide-y">
                            <li v-for="entry in recentActivity" :key="entry.id" class="py-2.5 text-sm">
                                <div class="font-medium">{{ humanizeAction(entry.action) }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ entry.organization?.name ?? 'Platform' }} · {{ entry.user?.name ?? 'System' }} · {{ formatTime(entry.created_at) }}
                                </div>
                            </li>
                        </ul>
                        <p v-else class="flex items-center gap-2 text-sm text-muted-foreground"><Users class="size-4" /> No activity yet.</p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
