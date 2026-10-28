<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Plus, RefreshCw, Server } from 'lucide-vue-next';
import { ref } from 'vue';

interface PbxServerRow {
    id: number;
    name: string;
    driver: string;
    region: string | null;
    sip_domain: string | null;
    ari_url: string | null;
    is_active: boolean;
    status: string;
    organizations_count: number;
    last_health_check_at: string | null;
    last_health: { online: boolean; version: string | null; error: string | null } | null;
}

defineProps<{
    servers: PbxServerRow[];
    drivers: string[];
}>();

const adding = ref(false);
const form = useForm({
    name: '',
    driver: 'asterisk',
    region: '',
    sip_domain: '',
    ari_url: '',
    ari_username: '',
    ari_password: '',
    ami_host: '',
    ami_port: 5038,
    ami_username: '',
    ami_secret: '',
});

const submit = () =>
    form.post(route('platform.pbx-servers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            adding.value = false;
            form.reset();
        },
    });

const checking = ref<number | null>(null);
const checkHealth = (server: PbxServerRow) => {
    checking.value = server.id;
    router.post(route('platform.pbx-servers.health', { pbxServer: server.id }), {}, { preserveScroll: true, onFinish: () => (checking.value = null) });
};

const formatTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'medium' }) : 'Never');

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head title="PBX servers" />

    <AppLayout :breadcrumbs="[{ title: 'Platform', href: '/platform' }, { title: 'PBX Servers', href: '/platform/pbx-servers' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">PBX servers</h1>
                    <p class="text-sm text-muted-foreground">Asterisk servers managed by this control panel. Health is checked every minute by the scheduler.</p>
                </div>
                <Button @click="adding = true"><Plus class="size-4" /> Add server</Button>
            </div>

            <div v-if="servers.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="server in servers" :key="server.id">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div class="min-w-0">
                            <CardTitle class="truncate text-base">{{ server.name }}</CardTitle>
                            <CardDescription class="truncate">{{ server.ari_url ?? server.driver }}</CardDescription>
                        </div>
                        <StatusBadge :status="server.status" />
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5">
                            <dt class="text-muted-foreground">Driver</dt>
                            <dd>
                                <span class="capitalize">{{ server.driver }}</span>
                                <span v-if="server.driver === 'simulator'" class="text-xs text-amber-600"> (dev only)</span>
                            </dd>
                            <dt class="text-muted-foreground">Version</dt>
                            <dd>{{ server.last_health?.version ?? '—' }}</dd>
                            <dt class="text-muted-foreground">Organizations</dt>
                            <dd class="tabular-nums">{{ server.organizations_count }}</dd>
                            <dt class="text-muted-foreground">Last check</dt>
                            <dd>{{ formatTime(server.last_health_check_at) }}</dd>
                        </dl>
                        <p v-if="server.last_health?.error" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                            {{ server.last_health.error }}
                        </p>
                        <Button variant="outline" size="sm" class="w-full" :disabled="checking === server.id" @click="checkHealth(server)">
                            <RefreshCw class="size-4" :class="{ 'animate-spin': checking === server.id }" />
                            Check health now
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <Card v-else>
                <CardContent class="flex flex-col items-center gap-3 py-14 text-center">
                    <Server class="size-8 text-muted-foreground" />
                    <p class="font-medium">No PBX servers yet</p>
                    <p class="max-w-sm text-sm text-muted-foreground">Add an Asterisk server with ARI enabled. Keep ARI and AMI on a private network.</p>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="adding" @update:open="adding = $event">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Add PBX server</DialogTitle>
                    <DialogDescription>Credentials are encrypted at rest. The connection is verified by the health check, not on save.</DialogDescription>
                </DialogHeader>
                <form id="add-server" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" required placeholder="Colombo primary" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="driver">Driver</Label>
                        <select id="driver" v-model="form.driver" :class="selectClass">
                            <option v-for="d in drivers" :key="d" :value="d">{{ d }}</option>
                        </select>
                        <InputError :message="form.errors.driver" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="region">Region</Label>
                        <Input id="region" v-model="form.region" placeholder="ap-south" />
                    </div>
                    <template v-if="form.driver === 'asterisk'">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="sip_domain">SIP domain</Label>
                            <Input id="sip_domain" v-model="form.sip_domain" placeholder="sip.example.com" />
                            <InputError :message="form.errors.sip_domain" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="ari_url">ARI URL</Label>
                            <Input id="ari_url" v-model="form.ari_url" placeholder="http://10.0.0.5:8088/ari" />
                            <InputError :message="form.errors.ari_url" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ari_username">ARI username</Label>
                            <Input id="ari_username" v-model="form.ari_username" autocomplete="off" />
                            <InputError :message="form.errors.ari_username" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ari_password">ARI password</Label>
                            <Input id="ari_password" v-model="form.ari_password" type="password" autocomplete="new-password" />
                            <InputError :message="form.errors.ari_password" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ami_host">AMI host</Label>
                            <Input id="ami_host" v-model="form.ami_host" placeholder="10.0.0.5" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ami_port">AMI port</Label>
                            <Input id="ami_port" v-model.number="form.ami_port" type="number" />
                            <InputError :message="form.errors.ami_port" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ami_username">AMI username</Label>
                            <Input id="ami_username" v-model="form.ami_username" autocomplete="off" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ami_secret">AMI secret</Label>
                            <Input id="ami_secret" v-model="form.ami_secret" type="password" autocomplete="new-password" />
                        </div>
                    </template>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="adding = false">Cancel</Button>
                    <Button type="submit" form="add-server" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                        Add server
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
