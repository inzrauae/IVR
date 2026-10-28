<script setup lang="ts">
import CallDirectionIcon from '@/components/CallDirectionIcon.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatDuration } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import type { CallRow, Paginated } from '@/types/calls';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Info, Play, Search, Trash2 } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

interface RecordingRow {
    id: number;
    duration: number | null;
    created_at: string;
    call: CallRow | null;
}

const props = defineProps<{
    recordings: Paginated<RecordingRow>;
    filters: { search: string; date: string | null };
    mode: string;
    can: { download: boolean; delete: boolean; settings: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Call Recordings', href: '/recordings' }];

const filters = reactive({ search: props.filters.search, date: props.filters.date ?? '' });
let timer: ReturnType<typeof setTimeout> | undefined;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(route('recordings.index'), Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), {
                preserveState: true,
                replace: true,
            }),
        300,
    );
});

// Load audio only when asked, since each file streams from the PBX.
const playing = ref<number | null>(null);
const removing = ref<RecordingRow | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('recordings.destroy', { recording: removing.value.id }), {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        });
};
</script>

<template>
    <Head title="Call Recordings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader
                title="Call Recordings"
                description="Listen to recorded calls. Recordings are kept on the phone system and streamed on demand."
            >
                <div class="relative">
                    <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="filters.search" class="w-56 pl-8" placeholder="Number or name" aria-label="Search recordings" />
                </div>
                <Input v-model="filters.date" type="date" class="w-40" aria-label="Date" />
            </PageHeader>

            <div v-if="mode === 'none'" class="flex items-start gap-3 rounded-lg border bg-muted/30 p-4 text-sm">
                <Info class="mt-0.5 size-4 shrink-0 text-sky-600" />
                <p>
                    Call recording is turned off.
                    <Link v-if="can.settings" :href="route('organization-settings.show', { section: 'calls' })" class="underline"
                        >Turn it on in call settings</Link
                    >
                    <template v-else>An administrator can turn it on in call settings.</template>
                </p>
            </div>

            <Card>
                <CardContent class="p-0">
                    <ul class="divide-y">
                        <li v-for="rec in recordings.data" :key="rec.id" class="flex flex-wrap items-center gap-4 px-4 py-3 text-sm">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <CallDirectionIcon v-if="rec.call" :direction="rec.call.direction" />
                                <div class="min-w-0">
                                    <Link
                                        v-if="rec.call"
                                        :href="route('call-history.show', { call: rec.call.id })"
                                        class="font-medium hover:underline"
                                    >
                                        {{ rec.call.from_name ?? rec.call.from_number ?? 'Unknown' }} →
                                        {{ rec.call.to_name ?? rec.call.to_number ?? 'Unknown' }}
                                    </Link>
                                    <span v-else class="font-medium">Recording #{{ rec.id }}</span>
                                    <div class="text-xs text-muted-foreground">
                                        {{ formatDateTime(rec.created_at) }} · {{ formatDuration(rec.duration) }}
                                    </div>
                                </div>
                            </div>
                            <audio
                                v-if="playing === rec.id"
                                controls
                                autoplay
                                class="h-9 w-full sm:w-72"
                                :src="route('recordings.play', { recording: rec.id })"
                            />
                            <Button v-else variant="outline" size="sm" @click="playing = rec.id"><Play class="size-3.5" /> Play</Button>
                            <Button
                                v-if="can.download"
                                variant="ghost"
                                size="icon"
                                as="a"
                                :href="route('recordings.download', { recording: rec.id })"
                                aria-label="Download"
                            >
                                <Download class="size-4" />
                            </Button>
                            <Button v-if="can.delete" variant="ghost" size="icon" aria-label="Delete recording" @click="removing = rec">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </li>
                        <li v-if="!recordings.data.length" class="px-4 py-12 text-center text-sm text-muted-foreground">No recordings found.</li>
                    </ul>
                    <Pagination :page="recordings" />
                </CardContent>
            </Card>
        </div>

        <ConfirmDialog
            :open="removing !== null"
            title="Delete this recording?"
            description="The audio file is removed from the phone system and cannot be recovered."
            confirm-label="Delete recording"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
