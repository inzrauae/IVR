<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DestinationSelect, { type DestinationOption } from '@/components/DestinationSelect.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ToggleField from '@/components/ToggleField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Moon, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Did {
    id: number;
    number: string;
    description: string | null;
    destination: string | null;
    after_hours_destination: string | null;
    active: boolean;
    sip_trunk_id: number | null;
    trunk: string | null;
    calls_30d: number;
}

const props = defineProps<{
    dids: Did[];
    trunks: { id: number; name: string }[];
    destinations: DestinationOption[];
    can: { manage: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'DID Numbers', href: '/dids' }];

const label = (value: string | null) => {
    if (!value) return '—';
    if (value.startsWith('external:')) return `Forward to ${value.slice(9)}`;
    return props.destinations.find((d) => d.value === value)?.label ?? 'Missing destination';
};

const blank = {
    number: '',
    description: '',
    sip_trunk_id: null as number | null,
    destination: null as string | null,
    after_hours_destination: null as string | null,
    active: true,
};
const editing = ref<Did | 'new' | null>(null);
const form = useForm({ ...blank });
const splitHours = ref(false);

const open = (did: Did | 'new') => {
    form.clearErrors();
    form.defaults(did === 'new' ? { ...blank } : { ...blank, ...did, description: did.description ?? '' });
    form.reset();
    splitHours.value = did !== 'new' && !!did.after_hours_destination;
    editing.value = did;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    const payload = form.transform((d) => ({
        ...d,
        description: d.description || null,
        after_hours_destination: splitHours.value ? d.after_hours_destination : null,
    }));
    if (editing.value === 'new') payload.post(route('dids.store'), options);
    else if (editing.value) payload.put(route('dids.update', { did: editing.value.id }), options);
};

const removing = ref<Did | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('dids.destroy', { did: removing.value.id }), { preserveScroll: true, onFinish: () => (removing.value = null) });
};
</script>

<template>
    <Head title="DID Numbers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="DID Numbers" description="Your public phone numbers and where each one rings.">
                <Button v-if="can.manage" @click="open('new')"><Plus class="size-4" /> Add number</Button>
            </PageHeader>

            <Card>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Number</th>
                                <th class="px-4 py-3 font-medium">Rings</th>
                                <th class="px-4 py-3 font-medium">Trunk</th>
                                <th class="px-4 py-3 font-medium">Calls (30 days)</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th v-if="can.manage" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="did in dids" :key="did.id" class="hover:bg-muted/30">
                                <td class="px-4 py-3">
                                    <div class="font-mono font-semibold">+{{ did.number }}</div>
                                    <div class="text-xs text-muted-foreground">{{ did.description }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    {{ label(did.destination) }}
                                    <div v-if="did.after_hours_destination" class="flex items-center gap-1 text-xs text-muted-foreground">
                                        <Moon class="size-3" /> After hours: {{ label(did.after_hours_destination) }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ did.trunk ?? 'Any' }}</td>
                                <td class="px-4 py-3 tabular-nums">{{ did.calls_30d }}</td>
                                <td class="px-4 py-3"><StatusBadge :status="did.active ? 'active' : 'disabled'" /></td>
                                <td v-if="can.manage" class="px-4 py-3 text-right">
                                    <Button variant="ghost" size="icon" :aria-label="`Edit +${did.number}`" @click="open(did)"
                                        ><Pencil class="size-4"
                                    /></Button>
                                    <Button variant="ghost" size="icon" :aria-label="`Remove +${did.number}`" @click="removing = did"
                                        ><Trash2 class="size-4 text-destructive"
                                    /></Button>
                                </td>
                            </tr>
                            <tr v-if="!dids.length">
                                <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">
                                    No numbers yet.
                                    <template v-if="can.manage && !trunks.length">
                                        Add a <Link :href="route('trunks.index')" class="underline">SIP trunk</Link> first, then the numbers your
                                        carrier assigned to it.
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
            <p class="text-xs text-muted-foreground">
                Open hours come from
                <Link :href="route('organization-settings.show', { section: 'business-hours' })" class="underline">Business hours</Link>.
            </p>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'Add number' : `Edit +${form.number}` }}</DialogTitle>
                    <DialogDescription>Calls to this number go to the destination you choose.</DialogDescription>
                </DialogHeader>
                <form id="did-form" class="grid gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="did-number">Number (with country code)</Label>
                        <Input id="did-number" v-model="form.number" required inputmode="tel" placeholder="94112345678" />
                        <InputError :message="form.errors.number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="did-desc">Description</Label>
                        <Input id="did-desc" v-model="form.description" maxlength="255" placeholder="e.g. Main line" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="did-trunk">Arrives on trunk</Label>
                        <NativeSelect id="did-trunk" v-model="form.sip_trunk_id">
                            <option :value="null">Any trunk</option>
                            <option v-for="t in trunks" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.sip_trunk_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="did-dest">{{ splitHours ? 'During open hours, ring' : 'Ring' }}</Label>
                        <DestinationSelect id="did-dest" v-model="form.destination" :options="destinations" empty-label="Choose a destination…" />
                        <InputError :message="form.errors.destination" />
                    </div>
                    <ToggleField
                        v-model="splitHours"
                        label="Different destination after hours"
                        description="Used outside business hours and on holidays."
                    />
                    <div v-if="splitHours" class="grid gap-2">
                        <Label for="did-after">After hours, ring</Label>
                        <DestinationSelect
                            id="did-after"
                            v-model="form.after_hours_destination"
                            :options="destinations"
                            empty-label="Same as open hours"
                        />
                        <InputError :message="form.errors.after_hours_destination" />
                    </div>
                    <ToggleField v-model="form.active" label="Active" description="Inactive numbers reject calls." />
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="did-form" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Remove +${removing?.number}?`"
            description="Calls to this number will no longer reach your organization."
            confirm-label="Remove number"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
