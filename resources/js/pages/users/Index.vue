<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Member {
    id: number;
    name: string;
    email: string;
    status: string;
    role: string | null;
    last_login_at: string | null;
    can: { update: boolean; delete: boolean };
}

const props = defineProps<{
    users: Member[];
    roles: Record<string, string>;
    seats: { used: number; limit: number | null };
    can: { create: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users & Permissions', href: '/users' }];

const atLimit = computed(() => props.seats.limit !== null && props.seats.used >= props.seats.limit);

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50';

// Create
const creating = ref(false);
const createForm = useForm({ name: '', email: '', password: '', role: 'agent' });
const submitCreate = () =>
    createForm.post(route('users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            creating.value = false;
            createForm.reset();
        },
    });

// Update role / status inline
const updating = ref<number | null>(null);
const update = (member: Member, changes: Partial<Pick<Member, 'role' | 'status'>>) => {
    updating.value = member.id;
    router.patch(
        route('users.update', { user: member.id }),
        { role: changes.role ?? member.role, status: changes.status ?? member.status },
        { preserveScroll: true, onFinish: () => (updating.value = null) },
    );
};

// Delete
const removing = ref<Member | null>(null);
const confirmRemove = () => {
    if (!removing.value) return;
    router.delete(route('users.destroy', { user: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
};

const roleLabel = (role: string | null) => (role ? (props.roles[role] ?? role.replace(/_/g, ' ')) : '—');
const formatDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : 'Never');
</script>

<template>
    <Head title="Users & Permissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Users & Permissions</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ seats.used }} of {{ seats.limit ?? 'unlimited' }} seats used.
                        <span v-if="atLimit">Upgrade your package to add more users.</span>
                    </p>
                </div>
                <Button v-if="can.create" :disabled="atLimit" @click="creating = true">
                    <Plus class="size-4" />
                    Add user
                </Button>
            </div>

            <Card>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Role</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Last login</th>
                                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="member in users" :key="member.id" :class="{ 'opacity-60': updating === member.id }">
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ member.name }}</div>
                                    <div class="text-xs text-muted-foreground">{{ member.email }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <select
                                        v-if="member.can.update"
                                        :class="selectClass"
                                        class="w-56"
                                        :value="member.role ?? ''"
                                        :aria-label="`Role for ${member.name}`"
                                        @change="update(member, { role: ($event.target as HTMLSelectElement).value })"
                                    >
                                        <option v-for="(label, value) in roles" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                    <span v-else>{{ roleLabel(member.role) }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <button
                                        v-if="member.can.update"
                                        type="button"
                                        :title="member.status === 'active' ? 'Disable user' : 'Enable user'"
                                        @click="update(member, { status: member.status === 'active' ? 'disabled' : 'active' })"
                                    >
                                        <StatusBadge :status="member.status" />
                                    </button>
                                    <StatusBadge v-else :status="member.status" />
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ formatDate(member.last_login_at) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        v-if="member.can.delete"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Remove ${member.name}`"
                                        @click="removing = member"
                                    >
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
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
                    <DialogTitle>Add user</DialogTitle>
                    <DialogDescription>Share the temporary password securely; the user can change it after signing in.</DialogDescription>
                </DialogHeader>
                <form id="create-user" class="grid gap-4" @submit.prevent="submitCreate">
                    <div class="grid gap-2">
                        <Label for="new-name">Name</Label>
                        <Input id="new-name" v-model="createForm.name" required autocomplete="off" />
                        <InputError :message="createForm.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="new-email">Email</Label>
                        <Input id="new-email" v-model="createForm.email" type="email" required autocomplete="off" />
                        <InputError :message="createForm.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="new-password">Temporary password</Label>
                        <Input id="new-password" v-model="createForm.password" type="password" required autocomplete="new-password" />
                        <InputError :message="createForm.errors.password" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="new-role">Role</Label>
                        <select id="new-role" v-model="createForm.role" :class="selectClass">
                            <option v-for="(label, value) in roles" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <InputError :message="createForm.errors.role" />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="creating = false">Cancel</Button>
                    <Button type="submit" form="create-user" :disabled="createForm.processing">
                        <LoaderCircle v-if="createForm.processing" class="size-4 animate-spin" />
                        Add user
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="removing !== null" @update:open="!$event && (removing = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove {{ removing?.name }}?</DialogTitle>
                    <DialogDescription>They will lose access immediately. This cannot be undone.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="removing = null">Cancel</Button>
                    <Button variant="destructive" @click="confirmRemove">Remove user</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
