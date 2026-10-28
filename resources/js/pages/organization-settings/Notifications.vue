<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import OrganizationSettingsLayout from '@/layouts/OrganizationSettingsLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { reactive } from 'vue';

type Key = 'missed_call_emails' | 'voicemail_emails' | 'trunk_failure_emails' | 'billing_emails';

const props = defineProps<{
    section: string;
    canManage: boolean;
    settings: Record<Key, string[]>;
}>();

const channels: { key: Key; label: string; description: string }[] = [
    { key: 'missed_call_emails', label: 'Missed calls', description: 'Summary of calls nobody answered' },
    { key: 'voicemail_emails', label: 'Voicemail', description: 'New voicemail left on shared mailboxes' },
    { key: 'trunk_failure_emails', label: 'Trunk failures', description: 'A SIP trunk goes offline or rejects calls' },
    { key: 'billing_emails', label: 'Billing', description: 'Invoices issued and payment reminders' },
];

// Edited as one address per line; sent as arrays.
const text = reactive(Object.fromEntries(channels.map((c) => [c.key, (props.settings[c.key] ?? []).join('\n')])) as Record<Key, string>);

const form = useForm(Object.fromEntries(channels.map((c) => [c.key, [] as string[]])) as Record<Key, string[]>);

const toList = (value: string) =>
    value
        .split(/[\n,;]+/)
        .map((s) => s.trim())
        .filter(Boolean);

const submit = () => {
    channels.forEach((c) => (form[c.key] = toList(text[c.key])));
    form.put(route('organization-settings.notifications.update'), { preserveScroll: true });
};

const errorFor = (key: Key) => {
    const errors = form.errors as Record<string, string>;
    return errors[key] ?? Object.entries(errors).find(([k]) => k.startsWith(`${key}.`))?.[1];
};
</script>

<template>
    <Head title="Notifications" />

    <OrganizationSettingsLayout
        :section="section"
        title="Notifications"
        description="Who gets emailed about important events. One address per line, up to 10 each."
        :can-manage="canManage"
    >
        <form class="space-y-6" @submit.prevent="submit">
            <fieldset :disabled="!canManage" class="grid gap-5 sm:grid-cols-2">
                <div v-for="c in channels" :key="c.key" class="grid gap-2">
                    <Label :for="c.key">{{ c.label }}</Label>
                    <p class="-mt-1 text-xs text-muted-foreground">{{ c.description }}</p>
                    <textarea
                        :id="c.key"
                        v-model="text[c.key]"
                        rows="3"
                        placeholder="ops@example.com"
                        class="rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    />
                    <InputError :message="errorFor(c.key)" />
                </div>
            </fieldset>
            <p class="text-xs text-muted-foreground">Missed-call, voicemail and trunk alerts start sending once those modules are live.</p>
            <Button v-if="canManage" type="submit" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                Save changes
            </Button>
        </form>
    </OrganizationSettingsLayout>
</template>
