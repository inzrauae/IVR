<script setup lang="ts">
import CallDirectionIcon from '@/components/CallDirectionIcon.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatDuration } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import type { CallRow, Paginated } from '@/types/calls';
import { Head, Link, router } from '@inertiajs/vue3';
import { Disc3, Download, Search } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

interface Filters {
    search: string | null;
    direction: string | null;
    status: string | null;
    extension: number | null;
    from: string | null;
    to: string | null;
}

const props = defineProps<{
    calls: Paginated<CallRow>;
    filters: Filters;
    extensions: { id: number; number: string; name: string }[];
    can: { recordings: boolean; export: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Call History', href: '/call-history' }];

const filters = reactive({ ...props.filters, search: props.filters.search ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const query = () => Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== null && v !== ''));

let timer: ReturnType<typeof setTimeout> | undefined;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('call-history.index'), query(), { preserveState: true, preserveScroll: true, replace: true }), 300);
});

const reset = () => Object.assign(filters, { search: null, direction: null, status: null, extension: null, from: null, to: null });
const party = (number: string | null, name: string | null) => name ?? number ?? 'Unknown';
</script>

<template>
    <Head title="Call History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Call History" description="Every call your organization made or received.">
                <Button v-if="can.export" variant="outline" as="a" :href="route('call-history.export', query())">
                    <Download class="size-4" /> Export CSV
                </Button>
            </PageHeader>

            <Card>
                <CardContent class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-7">
                    <div class="relative lg:col-span-2">
                        <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input v-model="filters.search" class="pl-8" placeholder="Number or name" aria-label="Search calls" />
                    </div>
                    <NativeSelect v-model="filters.direction" aria-label="Direction">
                        <option :value="null">All directions</option>
                        <option value="inbound">Inbound</option>
                        <option value="outbound">Outbound</option>
                        <option value="internal">Internal</option>
                    </NativeSelect>
                    <NativeSelect v-model="filters.status" aria-label="Status">
                        <option :value="null">All outcomes</option>
                        <option value="completed">Answered</option>
                        <option value="missed">Missed</option>
                        <option value="failed">Failed</option>
                        <option value="live">In progress</option>
                    </NativeSelect>
                    <NativeSelect v-model="filters.extension" aria-label="Extension">
                        <option :value="null">All extensions</option>
                        <option v-for="ext in extensions" :key="ext.id" :value="ext.id">{{ ext.number }} · {{ ext.name }}</option>
                    </NativeSelect>
                    <div class="flex items-center gap-2 sm:col-span-2">
                        <Input v-model="filters.from" type="date" aria-label="From date" />
                        <Input v-model="filters.to" type="date" aria-label="To date" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">When</th>
                                <th class="px-4 py-3 font-medium">From</th>
                                <th class="px-4 py-3 font-medium">To</th>
                                <th class="px-4 py-3 font-medium">Outcome</th>
                                <th class="px-4 py-3 font-medium">Talk time</th>
                                <th class="px-4 py-3"><span class="sr-only">Recording</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="call in calls.data"
                                :key="call.id"
                                class="cursor-pointer hover:bg-muted/30"
                                @click="router.visit(route('call-history.show', { call: call.id }))"
                            >
                                <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDateTime(call.started_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <CallDirectionIcon :direction="call.direction" :status="call.status" />
                                        <div>
                                            <Link
                                                :href="route('call-history.show', { call: call.id })"
                                                class="font-medium hover:underline"
                                                @click.stop
                                                >{{ party(call.from_number, call.from_name) }}</Link
                                            >
                                            <div v-if="call.from_name" class="text-xs text-muted-foreground">{{ call.from_number }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    {{ party(call.to_number, call.to_name) }}
                                    <div v-if="call.to_name" class="text-xs text-muted-foreground">{{ call.to_number }}</div>
                                </td>
                                <td class="px-4 py-3"><StatusBadge :status="call.status" /></td>
                                <td class="px-4 py-3 font-mono tabular-nums">{{ call.answered_at ? formatDuration(call.talk_time) : '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Disc3
                                        v-if="can.recordings && call.has_recording"
                                        class="inline size-4 text-muted-foreground"
                                        aria-label="Has recording"
                                    />
                                </td>
                            </tr>
                            <tr v-if="calls.data.length === 0">
                                <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">
                                    No calls match these filters.
                                    <button class="ml-1 underline" @click="reset">Clear filters</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <Pagination :page="calls" />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
