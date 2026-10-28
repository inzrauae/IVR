<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BellOff, CircleAlert, LoaderCircle, PhoneForwarded, Plus, Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface ExtensionRow {
    id: number;
    number: string;
    name: string;
    status: string;
    user: { id: number; name: string; email: string } | null;
    department: { id: number; name: string } | null;
    registered: boolean;
    do_not_disturb: boolean;
    forwarding: boolean;
    provisioning_status: 'pending' | 'provisioned' | 'failed';
    provisioning_error: string | null;
}

const props = defineProps<{
    extensions: ExtensionRow[];
    departments: { id: number; name: string }[];
    users: { id: number; name: string }[];
    nextNumber: string;
    limit: { used: number; limit: number | null };
    can: { manage: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Extensions', href: '/extensions' }];

const atLimit = computed(() => props.limit.limit !== null && props.limit.used >= props.limit.limit);

const search = ref('');
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.extensions;
    return props.extensions.filter((e) =>
        [e.number, e.name, e.user?.name, e.user?.email, e.department?.name].some((v) => v?.toLowerCase().includes(q)),
    );
});

// Create
const creating = ref(false);
const form = useForm({ number: '', name: '', user_id: null as number | null, department_id: null as number | null });
const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};
watch(
    () => form.user_id,
    (id) => {
        const user = props.users.find((u) => u.id === id);
        if (user && !form.name) form.name = user.name;
    },
);
const submit = () =>
    form
        .transform((data) => ({ ...data, number: data.number || null }))
        .post(route('extensions.store'), { onSuccess: () => (creating.value = false) });

// Keep provisioning badges current while the PBX works through pending changes.
let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    poll = setInterval(() => {
        if (props.extensions.some((e) => e.provisioning_status === 'pending')) {
            router.reload({ only: ['extensions'] });
        }
    }, 4000);
});
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <Head title="Extensions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Extensions</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ limit.used }} of {{ limit.limit ?? 'unlimited' }} extensions used.
                        <span v-if="atLimit">Upgrade your package to add more.</span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="relative">
                        <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input v-model="search" class="w-56 pl-8" placeholder="Search extensions" aria-label="Search extensions" />
                    </div>
                    <Button v-if="can.manage" :disabled="atLimit" @click="openCreate">
                        <Plus class="size-4" />
                        New extension
                    </Button>
                </div>
            </div>

            <Card>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Ext.</th>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Department</th>
                                <th class="px-4 py-3 font-medium">Device</th>
                                <th class="px-4 py-3 font-medium">Provisioning</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="ext in filtered" :key="ext.id" class="hover:bg-muted/30">
                                <td class="px-4 py-3 font-mono font-semibold tabular-nums">
                                    <Link :href="route('extensions.show', { extension: ext.id })" class="hover:underline">{{ ext.number }}</Link>
                                </td>
                                <td class="px-4 py-3">
                                    <Link :href="route('extensions.show', { extension: ext.id })" class="font-medium hover:underline">{{
                                        ext.name
                                    }}</Link>
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>{{ ext.user?.email ?? 'Not assigned to a user' }}</span>
                                        <BellOff v-if="ext.do_not_disturb" class="size-3.5 text-amber-600" aria-label="Do not disturb" />
                                        <PhoneForwarded v-if="ext.forwarding" class="size-3.5 text-sky-600" aria-label="Forwarding all calls" />
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ ext.department?.name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <StatusBadge :status="ext.registered ? 'registered' : 'offline'" />
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5" :title="ext.provisioning_error ?? undefined">
                                        <StatusBadge :status="ext.provisioning_status" />
                                        <CircleAlert v-if="ext.provisioning_error" class="size-4 text-destructive" />
                                    </span>
                                </td>
                                <td class="px-4 py-3"><StatusBadge :status="ext.status" /></td>
                            </tr>
                            <tr v-if="filtered.length === 0">
                                <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">
                                    <template v-if="extensions.length === 0">
                                        No extensions yet.
                                        <span v-if="can.manage">Create one to connect a desk phone, softphone or the web phone.</span>
                                    </template>
                                    <template v-else>No extensions match “{{ search }}”.</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="creating" @update:open="creating = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New extension</DialogTitle>
                    <DialogDescription>A SIP username and a strong password are generated automatically.</DialogDescription>
                </DialogHeader>
                <form id="create-extension" class="grid gap-4" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="ext-number">Number</Label>
                            <Input id="ext-number" v-model="form.number" inputmode="numeric" :placeholder="nextNumber" autocomplete="off" />
                            <InputError :message="form.errors.number" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="ext-name">Display name</Label>
                            <Input id="ext-name" v-model="form.name" required autocomplete="off" placeholder="Reception" />
                            <InputError :message="form.errors.name" />
                        </div>
                    </div>
                    <p class="-mt-2 text-xs text-muted-foreground">
                        Leave the number empty to use the next free one ({{ nextNumber }}). It cannot be changed later.
                    </p>
                    <div class="grid gap-2">
                        <Label for="ext-user">Assigned user</Label>
                        <NativeSelect id="ext-user" v-model="form.user_id">
                            <option :value="null">Nobody (shared phone)</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.user_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ext-department">Department</Label>
                        <NativeSelect id="ext-department" v-model="form.department_id">
                            <option :value="null">None</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.department_id" />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="creating = false">Cancel</Button>
                    <Button type="submit" form="create-extension" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                        Create extension
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
