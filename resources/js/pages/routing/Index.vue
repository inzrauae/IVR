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
import { formatRelative } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, LoaderCircle, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface RouteRow {
    id: number;
    name: string;
    pattern: string;
    strip_digits: number;
    prepend: string | null;
    priority: number;
    active: boolean;
    sip_trunk_id: number;
    trunk: string | null;
    trunk_active: boolean;
}

const props = defineProps<{
    routes: RouteRow[];
    trunks: { id: number; name: string; status: string }[];
    status: { pushed_at: string | null; error: string | null; keys: number };
    hours: { day: string; open: boolean; from: string; to: string }[];
    holidays: { name: string; date: string; recurring: boolean }[];
    timezone: string;
    can: { manage: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Call Routing', href: '/routing' }];

const blank = { name: '', pattern: '', sip_trunk_id: null as number | null, strip_digits: 0, prepend: '', priority: 10, active: true };
const editing = ref<RouteRow | 'new' | null>(null);
const form = useForm({ ...blank });

const open = (row: RouteRow | 'new') => {
    form.clearErrors();
    form.defaults(
        row === 'new'
            ? { ...blank, sip_trunk_id: props.trunks[0]?.id ?? null, priority: (props.routes.at(-1)?.priority ?? 0) + 10 }
            : { ...blank, ...row, prepend: row.prepend ?? '' },
    );
    form.reset();
    editing.value = row;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    const payload = form.transform((d) => ({ ...d, prepend: d.prepend || null }));
    if (editing.value === 'new') payload.post(route('routing.store'), options);
    else if (editing.value) payload.put(route('routing.update', { route: editing.value.id }), options);
};

const removing = ref<RouteRow | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('routing.destroy', { route: removing.value.id }), { preserveScroll: true, onFinish: () => (removing.value = null) });
};

// Try a number against the routes in order, the way the PBX does.
const testNumber = ref('');
const toRegex = (pattern: string) => {
    const map: Record<string, string> = { X: '[0-9]', Z: '[1-9]', N: '[2-9]', '.': '[0-9]+', '!': '[0-9]*', '+': '[+]' };
    const body = pattern
        .toUpperCase()
        .split(/(\[[^\]]+\])/)
        .map((part) => (part.startsWith('[') ? part : part.replace(/[XZN.!+]/g, (c) => map[c])))
        .join('');
    return new RegExp(`^${body}$`);
};
const match = computed(() => {
    const n = testNumber.value.replace(/[^\d+]/g, '');
    if (!n) return null;
    const hit = props.routes.find((r) => r.active && r.trunk_active && toRegex(r.pattern).test(n));
    if (!hit) return { route: null, dialled: null };
    return { route: hit, dialled: (hit.prepend ?? '') + n.slice(hit.strip_digits) };
});

const publish = () => router.post(route('routing.publish'), {}, { preserveScroll: true });
</script>

<template>
    <Head title="Call Routing" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader
                title="Call Routing"
                description="Outbound routes decide which trunk carries each number. Inbound calls follow each DID's destination."
            >
                <Button v-if="can.manage" variant="outline" @click="publish"><RefreshCw class="size-4" /> Push to PBX</Button>
                <Button v-if="can.manage" :disabled="!trunks.length" @click="open('new')"><Plus class="size-4" /> Add route</Button>
            </PageHeader>

            <div
                class="flex items-start gap-3 rounded-lg border p-4 text-sm"
                :class="status.error ? 'border-destructive/30 bg-destructive/10 text-destructive' : 'bg-muted/30'"
            >
                <CircleAlert v-if="status.error" class="mt-0.5 size-4 shrink-0" />
                <CircleCheck v-else class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                <p v-if="status.error">Routing could not be pushed to the PBX: {{ status.error }}. It is retried automatically.</p>
                <p v-else-if="status.pushed_at">
                    Routing is live on the PBX (last pushed {{ formatRelative(status.pushed_at) }}, {{ status.keys }} settings).
                </p>
                <p v-else>Routing has not been pushed yet. It is pushed automatically after your first change.</p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Outbound routes</CardTitle>
                    <CardDescription
                        >Checked from top to bottom; the first matching route on an active trunk is used. If a trunk is busy or down, the next match
                        is tried.</CardDescription
                    >
                </CardHeader>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-y bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">#</th>
                                <th class="px-4 py-3 font-medium">Route</th>
                                <th class="px-4 py-3 font-medium">Pattern</th>
                                <th class="px-4 py-3 font-medium">Number sent</th>
                                <th class="px-4 py-3 font-medium">Trunk</th>
                                <th v-if="can.manage" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in routes" :key="r.id" :class="{ 'opacity-50': !r.active }" class="hover:bg-muted/30">
                                <td class="px-4 py-3 tabular-nums text-muted-foreground">{{ r.priority }}</td>
                                <td class="px-4 py-3 font-medium">{{ r.name }}</td>
                                <td class="px-4 py-3 font-mono">{{ r.pattern }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    <template v-if="r.strip_digits || r.prepend">
                                        <span v-if="r.strip_digits">drop first {{ r.strip_digits }}</span
                                        ><span v-if="r.strip_digits && r.prepend">, </span><span v-if="r.prepend">add {{ r.prepend }}</span>
                                    </template>
                                    <template v-else>As dialled</template>
                                </td>
                                <td class="px-4 py-3">
                                    {{ r.trunk }}
                                    <StatusBadge v-if="!r.trunk_active" status="disabled" class="ml-1" />
                                </td>
                                <td v-if="can.manage" class="whitespace-nowrap px-4 py-3 text-right">
                                    <Button variant="ghost" size="icon" :aria-label="`Edit ${r.name}`" @click="open(r)"
                                        ><Pencil class="size-4"
                                    /></Button>
                                    <Button variant="ghost" size="icon" :aria-label="`Delete ${r.name}`" @click="removing = r"
                                        ><Trash2 class="size-4 text-destructive"
                                    /></Button>
                                </td>
                            </tr>
                            <tr v-if="!routes.length">
                                <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">
                                    No outbound routes, so calls to outside numbers are rejected.
                                    <template v-if="!trunks.length">
                                        Start by adding a <Link :href="route('trunks.index')" class="underline">SIP trunk</Link>.
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <div class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Test a number</CardTitle>
                        <CardDescription>See which route an outside call would take.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <Input v-model="testNumber" inputmode="tel" placeholder="e.g. 0771234567" aria-label="Number to test" />
                        <p v-if="match && match.route">
                            Uses <span class="font-medium">{{ match.route.name }}</span> on <span class="font-medium">{{ match.route.trunk }}</span
                            >, sending <span class="font-mono">{{ match.dialled }}</span
                            >.
                        </p>
                        <p v-else-if="match" class="text-destructive">No active route matches; the call would be rejected.</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Open hours</CardTitle>
                        <CardDescription>
                            {{ timezone }} · numbers with an after-hours destination use it outside these times.
                            <Link :href="route('organization-settings.show', { section: 'business-hours' })" class="underline">Change</Link>
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                            <li v-for="h in hours" :key="h.day" class="flex justify-between">
                                <span>{{ h.day }}</span>
                                <span :class="h.open ? '' : 'text-muted-foreground'">{{ h.open ? `${h.from}–${h.to}` : 'Closed' }}</span>
                            </li>
                        </ul>
                        <p v-if="holidays.length" class="mt-3 text-xs text-muted-foreground">
                            Closed on: {{ holidays.map((h) => `${h.name} (${h.date}${h.recurring ? ', yearly' : ''})`).join(', ') }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'Add outbound route' : `Edit ${form.name}` }}</DialogTitle>
                    <DialogDescription
                        >X = any digit, Z = 1–9, N = 2–9, [1-5] = a range, and a trailing . matches any number of digits.</DialogDescription
                    >
                </DialogHeader>
                <form id="route-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="route-name">Name</Label>
                        <Input id="route-name" v-model="form.name" required maxlength="100" placeholder="e.g. Sri Lanka mobiles" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="route-pattern">Pattern</Label>
                        <Input id="route-pattern" v-model="form.pattern" required class="font-mono" placeholder="07XXXXXXXX" />
                        <InputError :message="form.errors.pattern" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="route-trunk">Trunk</Label>
                        <NativeSelect id="route-trunk" v-model="form.sip_trunk_id">
                            <option v-for="t in trunks" :key="t.id" :value="t.id">
                                {{ t.name }}{{ t.status !== 'active' ? ' (disabled)' : '' }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.sip_trunk_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="route-strip">Remove leading digits</Label>
                        <Input id="route-strip" v-model.number="form.strip_digits" type="number" min="0" max="10" />
                        <InputError :message="form.errors.strip_digits" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="route-prepend">Then add prefix</Label>
                        <Input id="route-prepend" v-model="form.prepend" class="font-mono" placeholder="e.g. +94" />
                        <InputError :message="form.errors.prepend" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="route-priority">Order</Label>
                        <Input id="route-priority" v-model.number="form.priority" type="number" min="1" max="999" />
                        <InputError :message="form.errors.priority" />
                    </div>
                    <ToggleField v-model="form.active" class="sm:col-span-2" label="Active" />
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="route-form" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Delete ${removing?.name}?`"
            description="Numbers that only this route matched will stop going out."
            confirm-label="Delete route"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
