<script setup lang="ts">
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import OrganizationSettingsLayout from '@/layouts/OrganizationSettingsLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    section: string;
    canManage: boolean;
    diagnostics: {
        pbx: { name: string; driver: string; status: string; version: string | null; error: string | null; checked_at: string | null } | null;
        queue: { connection: string; pending: number | null; failed: number | null };
        app: { environment: string; laravel: string; php: string; timezone: string; server_time: string };
    };
}>();

const refreshing = ref(false);
const refresh = () => router.reload({ onStart: () => (refreshing.value = true), onFinish: () => (refreshing.value = false) });
const formatTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'medium' }) : 'Never');
</script>

<template>
    <Head title="Diagnostics" />

    <OrganizationSettingsLayout :section="section" title="Diagnostics" description="Live status of the services your phone system depends on">
        <div class="mb-4 flex justify-end">
            <Button variant="outline" size="sm" :disabled="refreshing" @click="refresh">
                <RefreshCw class="size-4" :class="{ 'animate-spin': refreshing }" /> Refresh
            </Button>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <Card class="md:col-span-2">
                <CardHeader class="flex-row items-center justify-between space-y-0">
                    <CardTitle class="text-base">PBX server</CardTitle>
                    <StatusBadge v-if="diagnostics.pbx" :status="diagnostics.pbx.status" />
                </CardHeader>
                <CardContent class="text-sm">
                    <dl v-if="diagnostics.pbx" class="grid grid-cols-[8rem_1fr] gap-y-1.5">
                        <dt class="text-muted-foreground">Server</dt>
                        <dd>{{ diagnostics.pbx.name }}</dd>
                        <dt class="text-muted-foreground">Driver</dt>
                        <dd class="capitalize">{{ diagnostics.pbx.driver }}</dd>
                        <dt class="text-muted-foreground">Version</dt>
                        <dd>{{ diagnostics.pbx.version ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Last check</dt>
                        <dd>{{ formatTime(diagnostics.pbx.checked_at) }}</dd>
                    </dl>
                    <p v-else class="text-muted-foreground">No PBX server is assigned to your organization yet. Your provider assigns one.</p>
                    <p v-if="diagnostics.pbx?.error" class="mt-3 rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ diagnostics.pbx.error }}</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle class="text-base">Background jobs</CardTitle></CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-[8rem_1fr] gap-y-1.5 text-sm">
                        <dt class="text-muted-foreground">Queue driver</dt>
                        <dd>{{ diagnostics.queue.connection }}</dd>
                        <dt class="text-muted-foreground">Pending</dt>
                        <dd class="tabular-nums">{{ diagnostics.queue.pending ?? 'n/a' }}</dd>
                        <dt class="text-muted-foreground">Failed</dt>
                        <dd class="tabular-nums" :class="{ 'font-medium text-destructive': (diagnostics.queue.failed ?? 0) > 0 }">
                            {{ diagnostics.queue.failed ?? 'n/a' }}
                        </dd>
                    </dl>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle class="text-base">Platform</CardTitle></CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-[8rem_1fr] gap-y-1.5 text-sm">
                        <dt class="text-muted-foreground">Environment</dt>
                        <dd>{{ diagnostics.app.environment }}</dd>
                        <dt class="text-muted-foreground">Laravel / PHP</dt>
                        <dd>{{ diagnostics.app.laravel }} / {{ diagnostics.app.php }}</dd>
                        <dt class="text-muted-foreground">Your time zone</dt>
                        <dd>{{ diagnostics.app.timezone }}</dd>
                        <dt class="text-muted-foreground">Server time</dt>
                        <dd>{{ formatTime(diagnostics.app.server_time) }}</dd>
                    </dl>
                </CardContent>
            </Card>
        </div>
    </OrganizationSettingsLayout>
</template>
