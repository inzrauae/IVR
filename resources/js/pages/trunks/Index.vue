<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ToggleField from '@/components/ToggleField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, LoaderCircle, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

interface Trunk {
    id: number;
    name: string;
    host: string;
    port: number;
    transport: string;
    username: string | null;
    register: boolean;
    from_domain: string | null;
    caller_id: string | null;
    max_channels: number;
    codecs: string;
    status: string;
    provisioning_status: string;
    provisioning_error: string | null;
    did_numbers_count: number;
    outbound_routes_count: number;
    has_password: boolean;
}

const props = defineProps<{ trunks: Trunk[]; codecs: string[]; can: { manage: boolean } }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'SIP Trunks', href: '/trunks' }];

const blank = {
    name: '',
    host: '',
    port: 5060,
    transport: 'udp',
    username: '',
    password: '',
    register: false,
    from_domain: '',
    caller_id: '',
    max_channels: 0,
    codecs: 'ulaw,alaw',
    status: 'active',
};
const editing = ref<Trunk | 'new' | null>(null);
const form = useForm({ ...blank });

const open = (trunk: Trunk | 'new') => {
    form.clearErrors();
    form.defaults(
        trunk === 'new'
            ? { ...blank }
            : {
                  ...blank,
                  ...trunk,
                  username: trunk.username ?? '',
                  from_domain: trunk.from_domain ?? '',
                  caller_id: trunk.caller_id ?? '',
                  password: '',
              },
    );
    form.reset();
    editing.value = trunk;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    const payload = form.transform((d) => ({
        ...d,
        username: d.username || null,
        password: d.password || null,
        from_domain: d.from_domain || null,
        caller_id: d.caller_id || null,
    }));
    if (editing.value === 'new') payload.post(route('trunks.store'), options);
    else if (editing.value) payload.put(route('trunks.update', { trunk: editing.value.id }), options);
};

const removing = ref<Trunk | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('trunks.destroy', { trunk: removing.value.id }), { preserveScroll: true, onFinish: () => (removing.value = null) });
};
const retry = (trunk: Trunk) => router.post(route('trunks.provision', { trunk: trunk.id }), {}, { preserveScroll: true });

let poll: ReturnType<typeof setInterval> | undefined;
onMounted(
    () => (poll = setInterval(() => props.trunks.some((t) => t.provisioning_status === 'pending') && router.reload({ only: ['trunks'] }), 4000)),
);
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <Head title="SIP Trunks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="SIP Trunks" description="Connections to your carriers. Inbound numbers arrive on a trunk; outbound routes choose one.">
                <Button v-if="can.manage" @click="open('new')"><Plus class="size-4" /> Add trunk</Button>
            </PageHeader>

            <div v-if="trunks.length" class="grid gap-4 lg:grid-cols-2">
                <Card v-for="trunk in trunks" :key="trunk.id">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardTitle class="flex items-center gap-2 text-base">
                                {{ trunk.name }}
                                <StatusBadge :status="trunk.status" />
                            </CardTitle>
                            <CardDescription class="font-mono">{{ trunk.transport }}://{{ trunk.host }}:{{ trunk.port }}</CardDescription>
                        </div>
                        <div v-if="can.manage" class="flex">
                            <Button variant="ghost" size="icon" :aria-label="`Edit ${trunk.name}`" @click="open(trunk)"
                                ><Pencil class="size-4"
                            /></Button>
                            <Button variant="ghost" size="icon" :aria-label="`Delete ${trunk.name}`" @click="removing = trunk"
                                ><Trash2 class="size-4 text-destructive"
                            /></Button>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                            <dt class="text-muted-foreground">Authentication</dt>
                            <dd>
                                {{
                                    trunk.username ? (trunk.register ? `Registers as ${trunk.username}` : `Digest as ${trunk.username}`) : 'IP-based'
                                }}
                            </dd>
                            <dt class="text-muted-foreground">Outbound caller ID</dt>
                            <dd>{{ trunk.caller_id ?? 'Extension / organization default' }}</dd>
                            <dt class="text-muted-foreground">Channels</dt>
                            <dd>{{ trunk.max_channels || 'Unlimited' }}</dd>
                            <dt class="text-muted-foreground">Used by</dt>
                            <dd>{{ trunk.did_numbers_count }} number(s), {{ trunk.outbound_routes_count }} route(s)</dd>
                        </dl>
                        <div class="flex items-center justify-between rounded-md bg-muted/40 px-3 py-2">
                            <span class="inline-flex items-center gap-2">
                                PBX: <StatusBadge :status="trunk.provisioning_status" />
                                <span v-if="trunk.provisioning_error" class="inline-flex items-center gap-1 text-xs text-destructive">
                                    <CircleAlert class="size-3.5" /> {{ trunk.provisioning_error }}
                                </span>
                            </span>
                            <Button v-if="can.manage && trunk.provisioning_status === 'failed'" size="sm" variant="outline" @click="retry(trunk)">
                                <RefreshCw class="size-3.5" /> Retry
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card v-else>
                <CardContent class="py-10 text-center text-sm text-muted-foreground">
                    No trunks yet. <span v-if="can.manage">Add the SIP details your carrier gave you to make and receive outside calls.</span>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'Add trunk' : `Edit ${form.name}` }}</DialogTitle>
                    <DialogDescription>Use the host, port and credentials from your carrier.</DialogDescription>
                </DialogHeader>
                <form id="trunk-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="trunk-name">Name</Label>
                        <Input id="trunk-name" v-model="form.name" required maxlength="100" placeholder="e.g. Dialog Business" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="trunk-host">Host</Label>
                        <Input id="trunk-host" v-model="form.host" required placeholder="sip.carrier.com" />
                        <InputError :message="form.errors.host" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="grid gap-2">
                            <Label for="trunk-port">Port</Label>
                            <Input id="trunk-port" v-model.number="form.port" type="number" min="1" max="65535" required />
                        </div>
                        <div class="grid gap-2">
                            <Label for="trunk-transport">Transport</Label>
                            <NativeSelect id="trunk-transport" v-model="form.transport">
                                <option value="udp">UDP</option>
                                <option value="tcp">TCP</option>
                                <option value="tls">TLS</option>
                            </NativeSelect>
                        </div>
                        <InputError class="col-span-2" :message="form.errors.port ?? form.errors.transport" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="trunk-user">Username</Label>
                        <Input id="trunk-user" v-model="form.username" autocomplete="off" placeholder="Leave empty for IP-based" />
                        <InputError :message="form.errors.username" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="trunk-pass">Password</Label>
                        <Input
                            id="trunk-pass"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="editing !== 'new' && editing?.has_password ? 'Unchanged' : ''"
                        />
                        <InputError :message="form.errors.password" />
                    </div>
                    <ToggleField
                        v-model="form.register"
                        class="sm:col-span-2"
                        label="Register with the carrier"
                        description="Needed when the carrier sends calls to a registered account rather than a fixed IP."
                    />
                    <div class="grid gap-2">
                        <Label for="trunk-cid">Outbound caller ID</Label>
                        <Input id="trunk-cid" v-model="form.caller_id" inputmode="tel" placeholder="+94112345678" />
                        <InputError :message="form.errors.caller_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="trunk-domain">From domain</Label>
                        <Input id="trunk-domain" v-model="form.from_domain" placeholder="Optional" />
                        <InputError :message="form.errors.from_domain" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="trunk-codecs">Codecs</Label>
                        <NativeSelect id="trunk-codecs" v-model="form.codecs">
                            <option v-for="codec in codecs" :key="codec" :value="codec">{{ codec }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-2">
                        <Label for="trunk-channels">Max channels</Label>
                        <Input id="trunk-channels" v-model.number="form.max_channels" type="number" min="0" max="1000" />
                        <p class="text-xs text-muted-foreground">0 = no limit</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="trunk-status">Status</Label>
                        <NativeSelect id="trunk-status" v-model="form.status">
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </NativeSelect>
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="trunk-form" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Delete ${removing?.name}?`"
            description="Its outbound routes are deleted and its numbers become unassigned. Calls on this carrier stop working."
            confirm-label="Delete trunk"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
