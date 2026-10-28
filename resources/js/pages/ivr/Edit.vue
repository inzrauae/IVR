<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DestinationSelect, { type DestinationOption } from '@/components/DestinationSelect.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Plus, Radio, Trash2, Upload, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Option = {
    digit: string;
    label: string;
    destination: string | null;
};

const props = defineProps<{
    menu: {
        id: number;
        number: string;
        name: string;
        timeout: number;
        max_retries: number;
        options: Option[];
        timeout_destination: string | null;
        invalid_destination: string | null;
        has_greeting: boolean;
        greeting_version: string | null;
        published_at: string | null;
    };
    digits: string[];
    destinations: DestinationOption[];
    can: { manage: boolean; publish: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'IVR Builder', href: '/ivr' },
    { title: props.menu.name, href: `/ivr/${props.menu.id}` },
];

// Live menus change what callers hear, so they need publish rights to edit.
const editable = computed(() => (props.menu.published_at ? props.can.publish : props.can.manage));

const form = useForm({
    number: props.menu.number,
    name: props.menu.name,
    timeout: props.menu.timeout,
    max_retries: props.menu.max_retries,
    options: props.menu.options.map((o) => ({ ...o, label: o.label ?? '' })) as Option[],
    timeout_destination: props.menu.timeout_destination,
    invalid_destination: props.menu.invalid_destination,
});

const unusedDigits = computed(() => props.digits.filter((d) => !form.options.some((o) => o.digit === d)));
const addOption = () => unusedDigits.value[0] && form.options.push({ digit: unusedDigits.value[0], label: '', destination: null });
const removeOption = (i: number) => form.options.splice(i, 1);

const save = () =>
    form
        .transform((d) => ({ ...d, options: d.options.map((o) => ({ ...o, label: o.label || null })) }))
        .put(route('ivr.update', { ivr: props.menu.id }), { preserveScroll: true });

const upload = useForm({ greeting: null as File | null });
const fileInput = ref<HTMLInputElement | null>(null);
const pick = (e: Event) => {
    upload.greeting = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (upload.greeting)
        upload.post(route('ivr.greeting.store', { ivr: props.menu.id }), {
            preserveScroll: true,
            onFinish: () => fileInput.value && (fileInput.value.value = ''),
        });
};

const publish = (live: boolean) => router.post(route('ivr.publish', { ivr: props.menu.id }), { live }, { preserveScroll: true });
const removing = ref(false);
const remove = () => router.delete(route('ivr.destroy', { ivr: props.menu.id }));
</script>

<template>
    <Head :title="menu.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-xl font-semibold tracking-tight">
                        {{ menu.name }} <StatusBadge :status="menu.published_at ? 'live' : 'draft'" />
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{
                            menu.published_at
                                ? `Live: callers hear this menu when they dial ${menu.number} or a number pointed at it.`
                                : 'Draft: callers do not hear this menu until it is published.'
                        }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <template v-if="can.publish">
                        <Button v-if="menu.published_at" variant="outline" @click="publish(false)">Take offline</Button>
                        <Button v-else class="bg-emerald-600 hover:bg-emerald-700" @click="publish(true)"><Radio class="size-4" /> Publish</Button>
                    </template>
                    <Button v-if="editable" variant="outline" class="text-destructive" @click="removing = true"
                        ><Trash2 class="size-4" /> Delete</Button
                    >
                </div>
            </div>

            <div v-if="menu.published_at && !can.publish" class="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
                This menu is live. Only users who can publish IVR menus may change it.
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="text-base">Key presses</CardTitle>
                        <CardDescription>What happens when the caller presses each key during or after the greeting.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-for="(option, i) in form.options"
                            :key="i"
                            class="grid items-start gap-2 rounded-lg border p-3 sm:grid-cols-[4.5rem_1fr_1.4fr_auto]"
                        >
                            <select
                                v-model="option.digit"
                                :disabled="!editable"
                                class="h-9 rounded-md border border-input bg-transparent px-2 text-center font-mono text-lg font-semibold dark:bg-background"
                                :aria-label="`Key for option ${i + 1}`"
                            >
                                <option v-for="d in digits" :key="d" :value="d" :disabled="d !== option.digit && !unusedDigits.includes(d)">
                                    {{ d }}
                                </option>
                            </select>
                            <Input
                                v-model="option.label"
                                :disabled="!editable"
                                placeholder="Label, e.g. Sales"
                                maxlength="60"
                                :aria-label="`Label for key ${option.digit}`"
                            />
                            <div>
                                <DestinationSelect v-model="option.destination" :options="destinations" empty-label="Choose a destination…" />
                                <InputError :message="(form.errors as Record<string, string>)[`options.${i}.destination`]" />
                            </div>
                            <Button v-if="editable" variant="ghost" size="icon" :aria-label="`Remove key ${option.digit}`" @click="removeOption(i)"
                                ><X class="size-4"
                            /></Button>
                        </div>
                        <p v-if="!form.options.length" class="text-sm text-muted-foreground">
                            No keys yet. Callers will follow the “no input” destination.
                        </p>
                        <Button v-if="editable && unusedDigits.length" variant="outline" size="sm" @click="addOption"
                            ><Plus class="size-4" /> Add key</Button
                        >
                    </CardContent>
                </Card>

                <div class="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Greeting</CardTitle>
                            <CardDescription>A WAV recording that lists the options. 8 kHz or 16 kHz mono works best.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <audio
                                v-if="menu.has_greeting"
                                :key="menu.greeting_version ?? ''"
                                controls
                                preload="none"
                                class="w-full"
                                :src="route('ivr.greeting', { ivr: menu.id, v: menu.greeting_version })"
                            />
                            <p v-else class="text-sm text-muted-foreground">No greeting yet; callers hear silence while they choose.</p>
                            <template v-if="editable">
                                <input ref="fileInput" type="file" accept=".wav,audio/wav" class="hidden" @change="pick" />
                                <Button variant="outline" size="sm" :disabled="upload.processing" @click="fileInput?.click()">
                                    <LoaderCircle v-if="upload.processing" class="size-4 animate-spin" />
                                    <Upload v-else class="size-4" />
                                    {{ menu.has_greeting ? 'Replace greeting' : 'Upload greeting' }}
                                </Button>
                                <InputError :message="upload.errors.greeting" />
                            </template>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle class="text-base">Settings</CardTitle></CardHeader>
                        <CardContent class="grid gap-4">
                            <div class="grid grid-cols-2 gap-3">
                                <div class="grid gap-2">
                                    <Label for="ivr-name">Name</Label>
                                    <Input id="ivr-name" v-model="form.name" :disabled="!editable" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="ivr-number">Number</Label>
                                    <Input id="ivr-number" v-model="form.number" :disabled="!editable" inputmode="numeric" />
                                </div>
                                <InputError class="col-span-2" :message="form.errors.name ?? form.errors.number" />
                                <div class="grid gap-2">
                                    <Label for="ivr-timeout">Wait for key (s)</Label>
                                    <Input id="ivr-timeout" v-model.number="form.timeout" :disabled="!editable" type="number" min="1" max="30" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="ivr-retries">Repeat menu</Label>
                                    <Input id="ivr-retries" v-model.number="form.max_retries" :disabled="!editable" type="number" min="0" max="5" />
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <Label for="ivr-timeout-dest">If no key is pressed</Label>
                                <DestinationSelect
                                    id="ivr-timeout-dest"
                                    v-model="form.timeout_destination"
                                    :options="destinations"
                                    empty-label="Hang up"
                                />
                                <InputError :message="form.errors.timeout_destination" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="ivr-invalid-dest">If a wrong key is pressed too often</Label>
                                <DestinationSelect
                                    id="ivr-invalid-dest"
                                    v-model="form.invalid_destination"
                                    :options="destinations"
                                    empty-label="Hang up"
                                />
                                <InputError :message="form.errors.invalid_destination" />
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <div v-if="editable" class="sticky bottom-4 flex justify-end">
                <Button size="lg" class="shadow-lg" :disabled="form.processing || !form.isDirty" @click="save">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    {{ menu.published_at ? 'Save and update live menu' : 'Save draft' }}
                </Button>
            </div>
        </div>

        <ConfirmDialog
            :open="removing"
            :title="`Delete ${menu.name}?`"
            description="Numbers and menus that send callers here will hang up instead."
            confirm-label="Delete menu"
            @confirm="remove"
            @cancel="removing = false"
        />
    </AppLayout>
</template>
