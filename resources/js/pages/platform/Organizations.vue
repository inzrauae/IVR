<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface OrganizationRow {
    id: number;
    name: string;
    slug: string;
    status: string;
    contact_email: string | null;
    users_count: number;
    package: string | null;
    subscription_status: string | null;
    pbx_server_id: number | null;
    pbx_server: string | null;
    suspension_reason: string | null;
    created_at: string;
}

const props = defineProps<{
    organizations: { data: OrganizationRow[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    pbxServers: { id: number; name: string }[];
    filters: { search: string | null };
}>();

const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(
        () => router.get(route('platform.organizations.index'), { search: value || undefined }, { preserveState: true, replace: true }),
        300,
    );
});

const suspending = ref<OrganizationRow | null>(null);
const suspendForm = useForm({ reason: '' });
const submitSuspend = () => {
    if (!suspending.value) return;
    suspendForm.post(route('platform.organizations.suspend', { organization: suspending.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            suspending.value = null;
            suspendForm.reset();
        },
    });
};

const reactivate = (org: OrganizationRow) =>
    router.post(route('platform.organizations.reactivate', { organization: org.id }), {}, { preserveScroll: true });

const assignServer = (org: OrganizationRow, value: string) =>
    router.put(
        route('platform.organizations.pbx-server', { organization: org.id }),
        { pbx_server_id: value ? Number(value) : null },
        { preserveScroll: true },
    );

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head title="Organizations" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Platform', href: '/platform' },
            { title: 'Organizations', href: '/platform/organizations' },
        ]"
    >
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Organizations</h1>
                    <p class="text-sm text-muted-foreground">{{ organizations.total }} total</p>
                </div>
                <div class="relative w-full sm:w-72">
                    <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="search" placeholder="Search name or email" class="pl-8" aria-label="Search organizations" />
                </div>
            </div>

            <Card>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Organization</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Package</th>
                                <th class="px-4 py-3 font-medium">Users</th>
                                <th class="px-4 py-3 font-medium">PBX server</th>
                                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="org in organizations.data" :key="org.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ org.name }}</div>
                                    <div class="text-xs text-muted-foreground">{{ org.contact_email ?? org.slug }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <StatusBadge :status="org.status" />
                                    <div
                                        v-if="org.suspension_reason"
                                        class="mt-1 max-w-48 truncate text-xs text-muted-foreground"
                                        :title="org.suspension_reason"
                                    >
                                        {{ org.suspension_reason }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    {{ org.package ?? '—' }}
                                    <div v-if="org.subscription_status" class="text-xs capitalize text-muted-foreground">
                                        {{ org.subscription_status.replace('_', ' ') }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 tabular-nums">{{ org.users_count }}</td>
                                <td class="px-4 py-3">
                                    <select
                                        :class="selectClass"
                                        :value="org.pbx_server_id ?? ''"
                                        :aria-label="`PBX server for ${org.name}`"
                                        @change="assignServer(org, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="">Unassigned</option>
                                        <option v-for="s in pbxServers" :key="s.id" :value="s.id">{{ s.name }}</option>
                                    </select>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button v-if="org.status === 'suspended'" size="sm" variant="outline" @click="reactivate(org)">Reactivate</Button>
                                    <Button v-else size="sm" variant="ghost" class="text-destructive" @click="suspending = org">Suspend</Button>
                                </td>
                            </tr>
                            <tr v-if="!organizations.data.length">
                                <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">No organizations match.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <nav v-if="organizations.links.length > 3" class="flex flex-wrap gap-1" aria-label="Pagination">
                <template v-for="link in organizations.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="rounded-md px-3 py-1.5 text-sm"
                        :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span v-else class="px-3 py-1.5 text-sm text-muted-foreground" v-html="link.label" />
                </template>
            </nav>
        </div>

        <Dialog :open="suspending !== null" @update:open="!$event && (suspending = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Suspend {{ suspending?.name }}?</DialogTitle>
                    <DialogDescription>All users of this organization lose portal and API access until it is reactivated.</DialogDescription>
                </DialogHeader>
                <form id="suspend-org" class="grid gap-2" @submit.prevent="submitSuspend">
                    <Label for="reason">Reason (shown to the organization)</Label>
                    <Input id="reason" v-model="suspendForm.reason" required maxlength="255" />
                    <InputError :message="suspendForm.errors.reason" />
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="suspending = null">Cancel</Button>
                    <Button type="submit" form="suspend-org" variant="destructive" :disabled="suspendForm.processing">Suspend</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
