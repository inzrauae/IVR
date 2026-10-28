<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDuration } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Clock, Download, PhoneCall, PhoneIncoming, PhoneMissed } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Day {
    date: string;
    inbound: number;
    outbound: number;
    internal: number;
    missed: number;
}

const props = defineProps<{
    range: { from: string; to: string; timezone: string };
    summary: {
        total: number;
        inbound: number;
        outbound: number;
        internal: number;
        answered: number;
        missed: number;
        failed: number;
        answer_rate: number | null;
        avg_talk: number | null;
        avg_ring: number | null;
        total_talk: number;
    };
    daily: Day[];
    hourly: number[];
    extensions: {
        id: number;
        number: string;
        name: string;
        calls: number;
        answered: number;
        missed: number;
        outbound: number;
        talk: number;
        avg_talk: number | null;
    }[];
    topCallers: { number: string; name: string | null; calls: number; missed: number }[];
    can: { export: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Analytics & Reports', href: '/reports' }];

const range = reactive({ from: props.range.from, to: props.range.to });
const apply = () => router.get(route('reports.index'), range, { preserveState: true, preserveScroll: true });
const preset = (days: number) => {
    const to = new Date();
    const from = new Date(Date.now() - (days - 1) * 86400000);
    range.from = from.toISOString().slice(0, 10);
    range.to = to.toISOString().slice(0, 10);
    apply();
};

// Stacked daily bars: one fixed color per direction (categorical slots 1–3).
const series = [
    { key: 'inbound', label: 'Inbound', color: 'var(--series-1)' },
    { key: 'outbound', label: 'Outbound', color: 'var(--series-2)' },
    { key: 'internal', label: 'Internal', color: 'var(--series-3)' },
] as const;
const dayTotal = (d: Day) => d.inbound + d.outbound + d.internal;
const dailyMax = computed(() => Math.max(1, ...props.daily.map(dayTotal)));
const hourlyMax = computed(() => Math.max(1, ...props.hourly));
const dayLabel = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
const labelEvery = computed(() => Math.ceil(props.daily.length / 10));

const hoveredDay = ref<number | null>(null);
const hoveredHour = ref<number | null>(null);
const showTable = ref(false);
</script>

<template>
    <Head title="Analytics & Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="viz-root flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Analytics & Reports" :description="`Finished calls, ${range.from} to ${range.to} (${props.range.timezone})`">
                <div class="flex rounded-md border">
                    <button v-for="d in [7, 30, 90]" :key="d" class="px-3 py-1.5 text-sm hover:bg-muted" @click="preset(d)">{{ d }}d</button>
                </div>
                <Input v-model="range.from" type="date" class="w-40" aria-label="From" @change="apply" />
                <Input v-model="range.to" type="date" class="w-40" aria-label="To" @change="apply" />
                <Button v-if="can.export" variant="outline" as="a" :href="route('reports.export', range)"><Download class="size-4" /> Export</Button>
            </PageHeader>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total calls"
                    :value="summary.total"
                    :icon="PhoneCall"
                    :hint="`${summary.inbound} in · ${summary.outbound} out · ${summary.internal} internal`"
                />
                <StatCard
                    label="Inbound answer rate"
                    :value="summary.answer_rate === null ? null : `${summary.answer_rate}%`"
                    :icon="PhoneIncoming"
                    :hint="`${summary.answered} answered overall`"
                />
                <StatCard label="Missed calls" :value="summary.missed" :icon="PhoneMissed" :hint="`${summary.failed} failed`" />
                <StatCard
                    label="Average talk time"
                    :value="summary.avg_talk === null ? null : formatDuration(summary.avg_talk)"
                    :icon="Clock"
                    :hint="`Avg ring ${formatDuration(summary.avg_ring)}`"
                />
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                <Card class="xl:col-span-2">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardTitle class="text-base">Calls per day</CardTitle>
                            <CardDescription>By direction</CardDescription>
                        </div>
                        <div class="flex items-center gap-4 text-xs text-muted-foreground">
                            <span v-for="s in series" :key="s.key" class="inline-flex items-center gap-1.5">
                                <span class="size-2.5 rounded-sm" :style="{ background: s.color }" />{{ s.label }}
                            </span>
                            <button class="underline" @click="showTable = !showTable">{{ showTable ? 'Chart' : 'Table' }}</button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <table v-if="showTable" class="w-full text-sm">
                            <thead class="text-left text-xs text-muted-foreground">
                                <tr>
                                    <th class="py-1 font-medium">Date</th>
                                    <th v-for="s in series" :key="s.key" class="py-1 text-right font-medium">{{ s.label }}</th>
                                    <th class="py-1 text-right font-medium">Missed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y tabular-nums">
                                <tr v-for="d in daily" :key="d.date">
                                    <td class="py-1">{{ dayLabel(d.date) }}</td>
                                    <td v-for="s in series" :key="s.key" class="py-1 text-right">{{ d[s.key] }}</td>
                                    <td class="py-1 text-right">{{ d.missed }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-else class="relative">
                            <div
                                class="flex h-56 items-end gap-[2px] border-b border-border"
                                role="img"
                                :aria-label="`Calls per day, peak ${dailyMax}`"
                            >
                                <div
                                    v-for="(d, i) in daily"
                                    :key="d.date"
                                    class="relative flex h-full flex-1 flex-col-reverse justify-start"
                                    @mouseenter="hoveredDay = i"
                                    @mouseleave="hoveredDay = null"
                                >
                                    <div
                                        v-for="(s, si) in series"
                                        :key="s.key"
                                        class="w-full"
                                        :class="{ 'rounded-t': si === series.length - 1 || series.slice(si + 1).every((n) => !d[n.key]) }"
                                        :style="{
                                            height: `${(d[s.key] / dailyMax) * 100}%`,
                                            background: s.color,
                                            marginTop: d[s.key] ? '2px' : '0',
                                            opacity: hoveredDay === null || hoveredDay === i ? 1 : 0.55,
                                        }"
                                    />
                                    <div
                                        v-if="hoveredDay === i"
                                        class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 w-36 -translate-x-1/2 rounded-md border bg-popover p-2 text-xs text-popover-foreground shadow-md"
                                    >
                                        <div class="mb-1 font-medium">{{ dayLabel(d.date) }} · {{ dayTotal(d) }} calls</div>
                                        <div v-for="s in series" :key="s.key" class="flex items-center justify-between gap-2">
                                            <span class="inline-flex items-center gap-1.5"
                                                ><span class="size-2 rounded-sm" :style="{ background: s.color }" />{{ s.label }}</span
                                            >
                                            <span class="tabular-nums">{{ d[s.key] }}</span>
                                        </div>
                                        <div class="mt-1 flex justify-between text-muted-foreground">
                                            <span>Missed</span><span class="tabular-nums">{{ d.missed }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-1 flex gap-[2px] text-[10px] text-muted-foreground">
                                <span v-for="(d, i) in daily" :key="d.date" class="flex-1 truncate text-center">{{
                                    i % labelEvery === 0 ? dayLabel(d.date) : ''
                                }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Inbound calls by hour</CardTitle>
                        <CardDescription>When customers call ({{ props.range.timezone }})</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="flex h-40 items-end gap-[2px] border-b border-border" role="img" aria-label="Inbound calls by hour of day">
                            <div
                                v-for="(count, hour) in hourly"
                                :key="hour"
                                class="relative flex h-full flex-1 items-end"
                                @mouseenter="hoveredHour = hour"
                                @mouseleave="hoveredHour = null"
                            >
                                <div
                                    class="w-full rounded-t"
                                    :style="{
                                        height: `${(count / hourlyMax) * 100}%`,
                                        background: 'var(--series-1)',
                                        opacity: hoveredHour === null || hoveredHour === hour ? 1 : 0.55,
                                    }"
                                />
                                <div
                                    v-if="hoveredHour === hour"
                                    class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 -translate-x-1/2 whitespace-nowrap rounded-md border bg-popover px-2 py-1 text-xs text-popover-foreground shadow-md"
                                >
                                    {{ String(hour).padStart(2, '0') }}:00 · {{ count }} call{{ count === 1 ? '' : 's' }}
                                </div>
                            </div>
                        </div>
                        <div class="mt-1 flex justify-between text-[10px] text-muted-foreground">
                            <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
                        </div>

                        <h3 class="mb-2 mt-6 text-sm font-medium">Top callers</h3>
                        <ul class="divide-y text-sm">
                            <li v-for="c in topCallers" :key="c.number" class="flex justify-between py-1.5">
                                <span class="truncate">{{ c.name ?? c.number }}</span>
                                <span class="tabular-nums text-muted-foreground"
                                    >{{ c.calls }}<template v-if="c.missed"> · {{ c.missed }} missed</template></span
                                >
                            </li>
                            <li v-if="!topCallers.length" class="py-2 text-muted-foreground">No inbound calls in this period.</li>
                        </ul>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">By extension</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-y bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Extension</th>
                                <th class="px-4 py-3 text-right font-medium">Calls</th>
                                <th class="px-4 py-3 text-right font-medium">Answered</th>
                                <th class="px-4 py-3 text-right font-medium">Missed</th>
                                <th class="px-4 py-3 text-right font-medium">Outbound</th>
                                <th class="px-4 py-3 text-right font-medium">Talk time</th>
                                <th class="px-4 py-3 text-right font-medium">Avg talk</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y tabular-nums">
                            <tr v-for="e in extensions" :key="e.id" class="hover:bg-muted/30">
                                <td class="px-4 py-2.5">
                                    <span class="font-mono font-semibold">{{ e.number }}</span> {{ e.name }}
                                </td>
                                <td class="px-4 py-2.5 text-right">{{ e.calls }}</td>
                                <td class="px-4 py-2.5 text-right">{{ e.answered }}</td>
                                <td class="px-4 py-2.5 text-right" :class="{ 'text-red-600': e.missed }">{{ e.missed }}</td>
                                <td class="px-4 py-2.5 text-right">{{ e.outbound }}</td>
                                <td class="px-4 py-2.5 text-right">{{ formatDuration(e.talk) }}</td>
                                <td class="px-4 py-2.5 text-right">{{ formatDuration(e.avg_talk) }}</td>
                            </tr>
                            <tr v-if="!extensions.length">
                                <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No extensions.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>

<style>
.viz-root {
    --series-1: #2a78d6;
    --series-2: #eb6834;
    --series-3: #1baf7a;
}
.dark .viz-root {
    --series-1: #3987e5;
    --series-2: #d95926;
    --series-3: #199e70;
}
</style>
