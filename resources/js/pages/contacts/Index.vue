<script setup lang="ts">
import CallDirectionIcon from '@/components/CallDirectionIcon.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatDuration } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import type { CallRow, Paginated } from '@/types/calls';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, LoaderCircle, Pencil, Phone, Plus, Search, Trash2, Upload } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

interface Contact {
    id: number;
    name: string;
    company: string | null;
    phone: string | null;
    mobile: string | null;
    email: string | null;
    notes: string | null;
}

const props = defineProps<{
    contacts: Paginated<Contact>;
    filters: { search: string };
    can: { manage: boolean; call: boolean; history: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Contacts', href: '/contacts' }];

const filters = reactive({ ...props.filters });
let timer: ReturnType<typeof setTimeout> | undefined;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(
        () => router.get(route('contacts.index'), filters.search ? { search: filters.search } : {}, { preserveState: true, replace: true }),
        300,
    );
});

const blank = { name: '', company: '', phone: '', mobile: '', email: '', notes: '' };
const editing = ref<Contact | 'new' | null>(null);
const form = useForm({ ...blank });
const open = (c: Contact | 'new') => {
    form.clearErrors();
    form.defaults(
        c === 'new' ? { ...blank } : (Object.fromEntries(Object.entries({ ...blank, ...c }).map(([k, v]) => [k, v ?? ''])) as typeof blank),
    );
    form.reset();
    editing.value = c;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    const payload = form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v || null])));
    if (editing.value === 'new') payload.post(route('contacts.store'), options);
    else if (editing.value) payload.put(route('contacts.update', { contact: editing.value.id }), options);
};

// Detail view with recent calls, fetched on open.
const viewing = ref<Contact | null>(null);
const calls = ref<CallRow[] | null>(null);
const view = async (c: Contact) => {
    viewing.value = c;
    calls.value = null;
    if (!props.can.history) return;
    const response = await fetch(route('contacts.calls', { contact: c.id }), { headers: { Accept: 'application/json' } });
    calls.value = response.ok ? await response.json() : [];
};

const removing = ref<Contact | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('contacts.destroy', { contact: removing.value.id }), { preserveScroll: true, onFinish: () => (removing.value = null) });
};

const importForm = useForm({ file: null as File | null });
const fileInput = ref<HTMLInputElement | null>(null);
const pick = (e: Event) => {
    importForm.file = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (importForm.file)
        importForm.post(route('contacts.import'), { preserveScroll: true, onFinish: () => fileInput.value && (fileInput.value.value = '') });
};
</script>

<template>
    <Head title="Contacts" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Contacts" description="Shared phone book. Known numbers show the contact's name in call lists.">
                <div class="relative">
                    <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="filters.search" class="w-56 pl-8" placeholder="Search contacts" aria-label="Search contacts" />
                </div>
                <Button variant="outline" as="a" :href="route('contacts.export')"><Download class="size-4" /> Export</Button>
                <template v-if="can.manage">
                    <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" @change="pick" />
                    <Button
                        variant="outline"
                        :disabled="importForm.processing"
                        title="CSV with columns: name, company, phone, mobile, email, notes"
                        @click="fileInput?.click()"
                    >
                        <LoaderCircle v-if="importForm.processing" class="size-4 animate-spin" /><Upload v-else class="size-4" /> Import CSV
                    </Button>
                    <Button @click="open('new')"><Plus class="size-4" /> New contact</Button>
                </template>
            </PageHeader>
            <InputError :message="importForm.errors.file" />

            <Card>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Phone</th>
                                <th class="px-4 py-3 font-medium">Email</th>
                                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="c in contacts.data" :key="c.id" class="hover:bg-muted/30">
                                <td class="px-4 py-3">
                                    <button class="text-left font-medium hover:underline" @click="view(c)">{{ c.name }}</button>
                                    <div class="text-xs text-muted-foreground">{{ c.company }}</div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    <div v-for="n in [c.phone, c.mobile].filter(Boolean)" :key="n!" class="flex items-center gap-1">
                                        {{ n }}
                                        <a
                                            v-if="can.call"
                                            :href="route('web-phone.index', { dial: n })"
                                            class="text-muted-foreground hover:text-foreground"
                                            :aria-label="`Call ${n}`"
                                        >
                                            <Phone class="size-3.5" />
                                        </a>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ c.email }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <template v-if="can.manage">
                                        <Button variant="ghost" size="icon" :aria-label="`Edit ${c.name}`" @click="open(c)"
                                            ><Pencil class="size-4"
                                        /></Button>
                                        <Button variant="ghost" size="icon" :aria-label="`Delete ${c.name}`" @click="removing = c"
                                            ><Trash2 class="size-4 text-destructive"
                                        /></Button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!contacts.data.length">
                                <td colspan="4" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ filters.search ? `No contacts match “${filters.search}”.` : 'No contacts yet.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <Pagination :page="contacts" />
                </CardContent>
            </Card>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'New contact' : `Edit ${form.name}` }}</DialogTitle>
                    <DialogDescription>Phone numbers are stored without spaces so calls can be matched.</DialogDescription>
                </DialogHeader>
                <form id="contact-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="c-name">Name</Label>
                        <Input id="c-name" v-model="form.name" required maxlength="255" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="c-company">Company</Label>
                        <Input id="c-company" v-model="form.company" maxlength="255" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="c-phone">Phone</Label>
                        <Input id="c-phone" v-model="form.phone" inputmode="tel" />
                        <InputError :message="form.errors.phone" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="c-mobile">Mobile</Label>
                        <Input id="c-mobile" v-model="form.mobile" inputmode="tel" />
                        <InputError :message="form.errors.mobile" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="c-email">Email</Label>
                        <Input id="c-email" v-model="form.email" type="email" />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="c-notes">Notes</Label>
                        <textarea
                            id="c-notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="2000"
                            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="contact-form" :disabled="form.processing"
                        ><LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="viewing !== null" @update:open="!$event && (viewing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ viewing?.name }}</DialogTitle>
                    <DialogDescription>{{ viewing?.company }}</DialogDescription>
                </DialogHeader>
                <dl class="grid grid-cols-[6rem_1fr] gap-y-1 text-sm">
                    <template v-if="viewing?.phone"
                        ><dt class="text-muted-foreground">Phone</dt>
                        <dd class="font-mono">{{ viewing.phone }}</dd></template
                    >
                    <template v-if="viewing?.mobile"
                        ><dt class="text-muted-foreground">Mobile</dt>
                        <dd class="font-mono">{{ viewing.mobile }}</dd></template
                    >
                    <template v-if="viewing?.email"
                        ><dt class="text-muted-foreground">Email</dt>
                        <dd>{{ viewing.email }}</dd></template
                    >
                </dl>
                <p v-if="viewing?.notes" class="whitespace-pre-line rounded-md bg-muted/40 p-3 text-sm">{{ viewing.notes }}</p>
                <div v-if="can.history">
                    <h3 class="mb-2 text-sm font-medium">Recent calls</h3>
                    <p v-if="calls === null" class="text-sm text-muted-foreground">Loading…</p>
                    <ul v-else class="divide-y text-sm">
                        <li v-for="call in calls" :key="call.id" class="flex items-center gap-3 py-2">
                            <CallDirectionIcon :direction="call.direction" :status="call.status" />
                            <span class="flex-1">{{ formatDateTime(call.started_at) }}</span>
                            <span class="font-mono text-xs">{{ call.answered_at ? formatDuration(call.talk_time) : '' }}</span>
                            <StatusBadge :status="call.status" />
                        </li>
                        <li v-if="!calls.length" class="py-2 text-muted-foreground">No calls with this contact.</li>
                    </ul>
                </div>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Delete ${removing?.name}?`"
            confirm-label="Delete contact"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
