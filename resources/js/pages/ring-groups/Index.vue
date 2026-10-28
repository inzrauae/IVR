<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DestinationSelect, { type DestinationOption } from '@/components/DestinationSelect.vue';
import ExtensionChecklist from '@/components/ExtensionChecklist.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Group {
    id: number;
    number: string;
    name: string;
    strategy: string;
    ring_timeout: number;
    members: number[];
    fallback_destination: string | null;
}

const props = defineProps<{
    groups: Group[];
    extensions: { id: number; number: string; name: string; registered: boolean; status: string }[];
    strategies: Record<string, string>;
    destinations: DestinationOption[];
    nextNumber: string;
    can: { manage: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Ring Groups', href: '/ring-groups' }];

const ext = (id: number) => props.extensions.find((e) => e.id === id);
const label = (value: string | null) =>
    !value
        ? 'Hang up'
        : value.startsWith('external:')
          ? `Forward to ${value.slice(9)}`
          : (props.destinations.find((d) => d.value === value)?.label ?? 'Missing');

const editing = ref<Group | 'new' | null>(null);
const form = useForm({
    number: '',
    name: '',
    strategy: 'ringall',
    ring_timeout: 20,
    members: [] as number[],
    fallback_destination: null as string | null,
});

const open = (group: Group | 'new') => {
    form.clearErrors();
    form.defaults(
        group === 'new'
            ? { number: props.nextNumber, name: '', strategy: 'ringall', ring_timeout: 20, members: [], fallback_destination: null }
            : { ...group, members: [...group.members] },
    );
    form.reset();
    editing.value = group;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('ring-groups.store'), options);
    else if (editing.value) form.put(route('ring-groups.update', { ringGroup: editing.value.id }), options);
};

const removing = ref<Group | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('ring-groups.destroy', { ringGroup: removing.value.id }), {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        });
};
</script>

<template>
    <Head title="Ring Groups" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Ring Groups" description="Ring several extensions from one number, all at once or one after another.">
                <Button v-if="can.manage" @click="open('new')"><Plus class="size-4" /> New ring group</Button>
            </PageHeader>

            <div v-if="groups.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="group in groups" :key="group.id">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardTitle class="text-base">{{ group.name }}</CardTitle>
                            <CardDescription>Dial {{ group.number }} · {{ strategies[group.strategy] }} · {{ group.ring_timeout }}s</CardDescription>
                        </div>
                        <div v-if="can.manage" class="flex">
                            <Button variant="ghost" size="icon" :aria-label="`Edit ${group.name}`" @click="open(group)"
                                ><Pencil class="size-4"
                            /></Button>
                            <Button variant="ghost" size="icon" :aria-label="`Delete ${group.name}`" @click="removing = group"
                                ><Trash2 class="size-4 text-destructive"
                            /></Button>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <ul class="divide-y">
                            <li v-for="(id, i) in group.members" :key="id" class="flex items-center justify-between py-1.5">
                                <span>
                                    <span v-if="group.strategy === 'sequential'" class="mr-2 text-xs text-muted-foreground">{{ i + 1 }}.</span>
                                    <span class="font-mono font-semibold">{{ ext(id)?.number }}</span> {{ ext(id)?.name ?? 'Deleted extension' }}
                                </span>
                                <span class="size-2 rounded-full" :class="ext(id)?.registered ? 'bg-emerald-500' : 'bg-muted-foreground/30'" />
                            </li>
                        </ul>
                        <p class="text-xs text-muted-foreground">If nobody answers: {{ label(group.fallback_destination) }}</p>
                    </CardContent>
                </Card>
            </div>
            <Card v-else>
                <CardContent class="py-10 text-center text-sm text-muted-foreground">No ring groups yet.</CardContent>
            </Card>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'New ring group' : `Edit ${form.name}` }}</DialogTitle>
                    <DialogDescription>For “one after another”, extensions ring in the order you tick them.</DialogDescription>
                </DialogHeader>
                <form id="rg-form" class="grid gap-4" @submit.prevent="submit">
                    <div class="grid grid-cols-[1fr_7rem] gap-3">
                        <div class="grid gap-2">
                            <Label for="rg-name">Name</Label>
                            <Input id="rg-name" v-model="form.name" required maxlength="100" placeholder="e.g. Sales team" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rg-number">Number</Label>
                            <Input id="rg-number" v-model="form.number" required inputmode="numeric" />
                        </div>
                        <InputError class="col-span-2" :message="form.errors.name ?? form.errors.number" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="rg-strategy">Ring</Label>
                            <NativeSelect id="rg-strategy" v-model="form.strategy">
                                <option v-for="(text, key) in strategies" :key="key" :value="key">{{ text }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-2">
                            <Label for="rg-timeout">{{ form.strategy === 'sequential' ? 'Seconds per extension' : 'Ring for (seconds)' }}</Label>
                            <Input id="rg-timeout" v-model.number="form.ring_timeout" type="number" min="5" max="120" />
                        </div>
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">Members</legend>
                        <ExtensionChecklist v-model="form.members" :extensions="extensions" />
                        <InputError :message="form.errors.members" />
                    </fieldset>
                    <div class="grid gap-2">
                        <Label for="rg-fallback">If nobody answers</Label>
                        <DestinationSelect id="rg-fallback" v-model="form.fallback_destination" :options="destinations" empty-label="Hang up" />
                        <InputError :message="form.errors.fallback_destination" />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="rg-form" :disabled="form.processing"
                        ><LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Delete ${removing?.name}?`"
            description="Numbers and menus that send callers here will hang up instead."
            confirm-label="Delete ring group"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
