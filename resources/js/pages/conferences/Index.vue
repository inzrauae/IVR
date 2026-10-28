<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import ToggleField from '@/components/ToggleField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Disc3, KeyRound, LoaderCircle, Pencil, Plus, Trash2, Users } from 'lucide-vue-next';
import { ref } from 'vue';

interface Room {
    id: number;
    number: string;
    name: string;
    pin: string | null;
    has_pin: boolean;
    max_members: number;
    record: boolean;
}

const props = defineProps<{ rooms: Room[]; nextNumber: string; can: { manage: boolean } }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Conferences', href: '/conferences' }];

const editing = ref<Room | 'new' | null>(null);
const form = useForm({ number: '', name: '', pin: '', max_members: 10, record: false as boolean });

const open = (room: Room | 'new') => {
    form.clearErrors();
    form.defaults(
        room === 'new' ? { number: props.nextNumber, name: '', pin: '', max_members: 10, record: false } : { ...room, pin: room.pin ?? '' },
    );
    form.reset();
    editing.value = room;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    const payload = form.transform((d) => ({ ...d, pin: d.pin || null }));
    if (editing.value === 'new') payload.post(route('conferences.store'), options);
    else if (editing.value) payload.put(route('conferences.update', { conference: editing.value.id }), options);
};

const removing = ref<Room | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('conferences.destroy', { conference: removing.value.id }), {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        });
};
</script>

<template>
    <Head title="Conferences" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Conferences" description="Meeting rooms anyone can dial into from an extension, or from outside through a DID.">
                <Button v-if="can.manage" @click="open('new')"><Plus class="size-4" /> New room</Button>
            </PageHeader>

            <div v-if="rooms.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="room in rooms" :key="room.id">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardTitle class="text-base">{{ room.name }}</CardTitle>
                            <CardDescription>Dial {{ room.number }}</CardDescription>
                        </div>
                        <div v-if="can.manage" class="flex">
                            <Button variant="ghost" size="icon" :aria-label="`Edit ${room.name}`" @click="open(room)"
                                ><Pencil class="size-4"
                            /></Button>
                            <Button variant="ghost" size="icon" :aria-label="`Delete ${room.name}`" @click="removing = room"
                                ><Trash2 class="size-4 text-destructive"
                            /></Button>
                        </div>
                    </CardHeader>
                    <CardContent class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                        <span class="inline-flex items-center gap-1"><Users class="size-4" /> Up to {{ room.max_members }}</span>
                        <span class="inline-flex items-center gap-1">
                            <KeyRound class="size-4" />
                            <template v-if="room.pin">PIN {{ room.pin }}</template>
                            <template v-else>{{ room.has_pin ? 'PIN protected' : 'No PIN' }}</template>
                        </span>
                        <span v-if="room.record" class="inline-flex items-center gap-1"><Disc3 class="size-4" /> Recorded</span>
                    </CardContent>
                </Card>
            </div>
            <Card v-else>
                <CardContent class="py-10 text-center text-sm text-muted-foreground">No conference rooms yet.</CardContent>
            </Card>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'New conference room' : `Edit ${form.name}` }}</DialogTitle>
                    <DialogDescription>Callers enter the PIN, if set, before joining.</DialogDescription>
                </DialogHeader>
                <form id="conf-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="conf-name">Name</Label>
                        <Input id="conf-name" v-model="form.name" required maxlength="100" placeholder="e.g. Weekly standup" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="conf-number">Number</Label>
                        <Input id="conf-number" v-model="form.number" required inputmode="numeric" />
                        <InputError :message="form.errors.number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="conf-pin">PIN</Label>
                        <Input id="conf-pin" v-model="form.pin" inputmode="numeric" placeholder="Optional, 4–10 digits" autocomplete="off" />
                        <InputError :message="form.errors.pin" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="conf-max">Maximum participants</Label>
                        <Input id="conf-max" v-model.number="form.max_members" type="number" min="2" max="100" />
                        <InputError :message="form.errors.max_members" />
                    </div>
                    <ToggleField
                        v-model="form.record"
                        class="sm:col-span-2"
                        label="Record meetings"
                        description="Each participant's call is recorded and appears under Call Recordings."
                    />
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="conf-form" :disabled="form.processing"
                        ><LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Delete ${removing?.name}?`"
            confirm-label="Delete room"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
