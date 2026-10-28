<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DestinationSelect, { type DestinationOption } from '@/components/DestinationSelect.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDuration } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Pause, Pencil, Play, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Member {
    id: number;
    number: string;
    name: string;
    registered: boolean;
    penalty: number;
    paused: boolean;
}

interface Queue {
    id: number;
    number: string;
    name: string;
    strategy: string;
    agent_timeout: number;
    wrapup_time: number;
    max_wait: number;
    fallback_destination: string | null;
    members: Member[];
    today: { calls: number; answered: number; avg_talk: number | null };
}

const props = defineProps<{
    queues: Queue[];
    extensions: { id: number; number: string; name: string }[];
    strategies: Record<string, string>;
    destinations: DestinationOption[];
    nextNumber: string;
    can: { manage: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Call Queues', href: '/queues' }];

const blank = () => ({
    number: props.nextNumber,
    name: '',
    strategy: 'rrmemory',
    agent_timeout: 15,
    wrapup_time: 5,
    max_wait: 300,
    members: [] as { id: number; penalty: number }[],
    fallback_destination: null as string | null,
});
const editing = ref<Queue | 'new' | null>(null);
const form = useForm(blank());

const open = (queue: Queue | 'new') => {
    form.clearErrors();
    form.defaults(queue === 'new' ? blank() : { ...queue, members: queue.members.map((m) => ({ id: m.id, penalty: m.penalty })) });
    form.reset();
    editing.value = queue;
};

const isMember = (id: number) => form.members.some((m) => m.id === id);
const toggleMember = (id: number) => {
    form.members = isMember(id) ? form.members.filter((m) => m.id !== id) : [...form.members, { id, penalty: 0 }];
};
const member = (id: number) => form.members.find((m) => m.id === id)!;

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('queues.store'), options);
    else if (editing.value) form.put(route('queues.update', { queue: editing.value.id }), options);
};

const pause = (queue: Queue, m: Member) =>
    router.post(route('queues.pause', { queue: queue.id, extension: m.id }), { paused: !m.paused }, { preserveScroll: true });

const removing = ref<Queue | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('queues.destroy', { queue: removing.value.id }), { preserveScroll: true, onFinish: () => (removing.value = null) });
};
</script>

<template>
    <Head title="Call Queues" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader
                title="Call Queues"
                description="Callers wait with hold music until an agent is free. Agents can be paused without leaving the queue."
            >
                <Button v-if="can.manage" @click="open('new')"><Plus class="size-4" /> New queue</Button>
            </PageHeader>

            <div v-if="queues.length" class="grid gap-4 lg:grid-cols-2">
                <Card v-for="queue in queues" :key="queue.id">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardTitle class="text-base">{{ queue.name }}</CardTitle>
                            <CardDescription>
                                Dial {{ queue.number }} · {{ strategies[queue.strategy] }} · max wait {{ formatDuration(queue.max_wait) }}
                            </CardDescription>
                        </div>
                        <div v-if="can.manage" class="flex">
                            <Button variant="ghost" size="icon" :aria-label="`Edit ${queue.name}`" @click="open(queue)"
                                ><Pencil class="size-4"
                            /></Button>
                            <Button variant="ghost" size="icon" :aria-label="`Delete ${queue.name}`" @click="removing = queue"
                                ><Trash2 class="size-4 text-destructive"
                            /></Button>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-4 text-sm">
                        <div class="grid grid-cols-3 gap-2 rounded-md bg-muted/40 p-3 text-center">
                            <div>
                                <div class="text-lg font-semibold tabular-nums">{{ queue.today.calls }}</div>
                                <div class="text-xs text-muted-foreground">Dialled today</div>
                            </div>
                            <div>
                                <div class="text-lg font-semibold tabular-nums">{{ queue.today.answered }}</div>
                                <div class="text-xs text-muted-foreground">Answered</div>
                            </div>
                            <div>
                                <div class="text-lg font-semibold tabular-nums">{{ formatDuration(queue.today.avg_talk) }}</div>
                                <div class="text-xs text-muted-foreground">Avg talk</div>
                            </div>
                        </div>
                        <ul class="divide-y">
                            <li v-for="m in queue.members" :key="m.id" class="flex items-center gap-3 py-1.5">
                                <span
                                    class="size-2 rounded-full"
                                    :class="m.paused ? 'bg-amber-500' : m.registered ? 'bg-emerald-500' : 'bg-muted-foreground/30'"
                                />
                                <span class="flex-1">
                                    <span class="font-mono font-semibold">{{ m.number }}</span> {{ m.name }}
                                    <span v-if="m.paused" class="text-xs text-amber-600">paused</span>
                                    <span v-if="m.penalty" class="text-xs text-muted-foreground"> · tier {{ m.penalty }}</span>
                                </span>
                                <Button v-if="can.manage" variant="ghost" size="sm" @click="pause(queue, m)">
                                    <Play v-if="m.paused" class="size-3.5" /><Pause v-else class="size-3.5" /> {{ m.paused ? 'Resume' : 'Pause' }}
                                </Button>
                            </li>
                            <li v-if="!queue.members.length" class="py-2 text-muted-foreground">No agents; callers go straight to the fallback.</li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
            <Card v-else>
                <CardContent class="py-10 text-center text-sm text-muted-foreground">No queues yet.</CardContent>
            </Card>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'New queue' : `Edit ${form.name}` }}</DialogTitle>
                    <DialogDescription>Agents in a lower tier get calls first; higher tiers only when lower ones are busy.</DialogDescription>
                </DialogHeader>
                <form id="queue-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="q-name">Name</Label>
                        <Input id="q-name" v-model="form.name" required maxlength="100" placeholder="e.g. Support" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="q-number">Number</Label>
                        <Input id="q-number" v-model="form.number" required inputmode="numeric" />
                        <InputError :message="form.errors.number" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="q-strategy">Distribute calls</Label>
                        <NativeSelect id="q-strategy" v-model="form.strategy">
                            <option v-for="(text, key) in strategies" :key="key" :value="key">{{ text }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-2">
                        <Label for="q-agent">Ring each agent (s)</Label>
                        <Input id="q-agent" v-model.number="form.agent_timeout" type="number" min="5" max="120" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="q-wrap">Wrap-up after a call (s)</Label>
                        <Input id="q-wrap" v-model.number="form.wrapup_time" type="number" min="0" max="300" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="q-wait">Maximum wait (s)</Label>
                        <Input id="q-wait" v-model.number="form.max_wait" type="number" min="30" max="3600" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="q-fallback">After maximum wait</Label>
                        <DestinationSelect id="q-fallback" v-model="form.fallback_destination" :options="destinations" empty-label="Hang up" />
                        <InputError :message="form.errors.fallback_destination" />
                    </div>
                    <fieldset class="grid gap-2 sm:col-span-2">
                        <legend class="mb-2 text-sm font-medium">Agents</legend>
                        <div class="max-h-60 space-y-1 overflow-y-auto rounded-md border p-2">
                            <div v-for="e in extensions" :key="e.id" class="flex items-center gap-3 rounded px-2 py-1 text-sm hover:bg-muted/50">
                                <input :id="`q-m-${e.id}`" type="checkbox" class="size-4" :checked="isMember(e.id)" @change="toggleMember(e.id)" />
                                <label :for="`q-m-${e.id}`" class="flex-1 cursor-pointer"
                                    ><span class="font-mono font-semibold">{{ e.number }}</span> {{ e.name }}</label
                                >
                                <select
                                    v-if="isMember(e.id)"
                                    v-model.number="member(e.id).penalty"
                                    class="h-7 rounded border bg-transparent px-1 text-xs dark:bg-background"
                                    :aria-label="`Tier for ${e.number}`"
                                >
                                    <option v-for="t in 10" :key="t - 1" :value="t - 1">Tier {{ t - 1 }}</option>
                                </select>
                            </div>
                            <p v-if="!extensions.length" class="text-muted-foreground">Create extensions first.</p>
                        </div>
                        <InputError :message="form.errors.members" />
                    </fieldset>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="queue-form" :disabled="form.processing"
                        ><LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Delete ${removing?.name}?`"
            description="Callers waiting in this queue are disconnected, and anything that sends callers here hangs up instead."
            confirm-label="Delete queue"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
