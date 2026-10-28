<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
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
import { Copy, KeyRound, LoaderCircle, Pencil, Plus, Send, Trash2, Webhook as WebhookIcon } from 'lucide-vue-next';
import { ref } from 'vue';

interface Webhook {
    id: number;
    url: string;
    events: string[];
    active: boolean;
    failing: boolean;
}

const props = defineProps<{
    webhooks: Webhook[];
    deliveries: {
        id: number;
        webhook_id: number;
        event: string;
        response_status: number | null;
        error: string | null;
        attempts: number;
        delivered_at: string | null;
        created_at: string;
    }[];
    events: Record<string, string>;
    secret: { id: number; secret: string } | null;
    apiBase: string;
    can: { manage: boolean; tokens: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Integrations', href: '/integrations' }];

const editing = ref<Webhook | 'new' | null>(null);
const form = useForm({ url: '', events: [] as string[], active: true as boolean });
const open = (w: Webhook | 'new') => {
    form.clearErrors();
    form.defaults(
        w === 'new' ? { url: '', events: Object.keys(props.events), active: true } : { url: w.url, events: [...w.events], active: w.active },
    );
    form.reset();
    editing.value = w;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('webhooks.store'), options);
    else if (editing.value) form.put(route('webhooks.update', { webhook: editing.value.id }), options);
};

const test = (w: Webhook) => router.post(route('webhooks.test', { webhook: w.id }), {}, { preserveScroll: true });
const rotate = (w: Webhook) => router.post(route('webhooks.rotate', { webhook: w.id }), {}, { preserveScroll: true });
const removing = ref<Webhook | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('webhooks.destroy', { webhook: removing.value.id }), { preserveScroll: true, onFinish: () => (removing.value = null) });
};

const copied = ref(false);
const copy = async (text: string) => {
    await navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
};
const urlOf = (id: number) => props.webhooks.find((w) => w.id === id)?.url ?? 'Deleted webhook';

const endpoints = [
    ['GET', '/me', 'The token’s user and organization'],
    ['GET', '/calls', 'Call history; filters: direction, status, since, number, per_page'],
    ['GET', '/calls/{id}', 'One call'],
    ['GET', '/extensions', 'Extensions with registration state'],
    ['GET', '/contacts', 'Contacts; filter: search'],
    ['POST', '/contacts', 'Create a contact (name, company, phone, mobile, email, notes)'],
    ['DELETE', '/contacts/{id}', 'Delete a contact'],
];
</script>

<template>
    <Head title="Integrations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Integrations" description="Send call events to your CRM or helpdesk, and read data with the REST API." />

            <div v-if="secret" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm">
                <p class="font-medium">Signing secret for {{ urlOf(secret.id) }}. Copy it now; it will not be shown again.</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="flex-1 truncate rounded bg-background px-2 py-1 font-mono">{{ secret.secret }}</code>
                    <Button size="sm" variant="outline" @click="copy(secret.secret)"
                        ><Copy class="size-3.5" /> {{ copied ? 'Copied' : 'Copy' }}</Button
                    >
                </div>
            </div>

            <Card>
                <CardHeader class="flex-row items-start justify-between space-y-0">
                    <div>
                        <CardTitle class="flex items-center gap-2 text-base"><WebhookIcon class="size-4" /> Webhooks</CardTitle>
                        <CardDescription>
                            We POST JSON to your URL for each event, signed with HMAC-SHA256 in the <code>X-CloudPBX-Signature</code> header (<code
                                >t=…,v1=…</code
                            >
                            over <code>"&lt;t&gt;.&lt;body&gt;"</code>). Failed deliveries are retried for several hours.
                        </CardDescription>
                    </div>
                    <Button v-if="can.manage" size="sm" @click="open('new')"><Plus class="size-4" /> Add webhook</Button>
                </CardHeader>
                <CardContent class="p-0">
                    <ul class="divide-y">
                        <li v-for="w in webhooks" :key="w.id" class="flex flex-wrap items-center gap-3 px-6 py-3 text-sm">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate font-mono">{{ w.url }}</span>
                                    <StatusBadge :status="!w.active ? 'disabled' : w.failing ? 'failed' : 'active'" />
                                </div>
                                <div class="text-xs text-muted-foreground">{{ w.events.join(', ') }}</div>
                            </div>
                            <template v-if="can.manage">
                                <Button variant="ghost" size="sm" @click="test(w)"><Send class="size-3.5" /> Test</Button>
                                <Button variant="ghost" size="sm" @click="rotate(w)"><KeyRound class="size-3.5" /> New secret</Button>
                                <Button variant="ghost" size="icon" aria-label="Edit webhook" @click="open(w)"><Pencil class="size-4" /></Button>
                                <Button variant="ghost" size="icon" aria-label="Delete webhook" @click="removing = w"
                                    ><Trash2 class="size-4 text-destructive"
                                /></Button>
                            </template>
                        </li>
                        <li v-if="!webhooks.length" class="px-6 py-8 text-center text-sm text-muted-foreground">No webhooks yet.</li>
                    </ul>
                </CardContent>
            </Card>

            <Card v-if="deliveries.length">
                <CardHeader>
                    <CardTitle class="text-base">Recent deliveries</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-y bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Event</th>
                                <th class="px-4 py-2 font-medium">Webhook</th>
                                <th class="px-4 py-2 font-medium">Result</th>
                                <th class="px-4 py-2 font-medium">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="d in deliveries" :key="d.id">
                                <td class="px-4 py-2 font-mono text-xs">{{ d.event }}</td>
                                <td class="max-w-xs truncate px-4 py-2 text-muted-foreground">{{ urlOf(d.webhook_id) }}</td>
                                <td class="px-4 py-2">
                                    <StatusBadge :status="d.delivered_at ? 'delivered' : d.attempts ? 'failed' : 'pending'" />
                                    <span class="ml-2 text-xs text-muted-foreground">{{
                                        d.error ?? (d.response_status ? `HTTP ${d.response_status}` : '')
                                    }}</span>
                                    <span v-if="d.attempts > 1" class="ml-1 text-xs text-muted-foreground">({{ d.attempts }} attempts)</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ formatRelative(d.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">REST API</CardTitle>
                    <CardDescription>
                        Send <code>Authorization: Bearer &lt;token&gt;</code> and <code>Accept: application/json</code>. Requests run with the
                        permissions of the token's owner.
                        <Link v-if="can.tokens" :href="route('organization-settings.show', { section: 'security' })" class="underline"
                            >Create an API token</Link
                        >
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <div class="flex items-center gap-2">
                        <code class="flex-1 truncate rounded bg-muted px-2 py-1 font-mono">{{ apiBase }}</code>
                        <Button size="sm" variant="outline" @click="copy(apiBase)"><Copy class="size-3.5" /> Copy</Button>
                    </div>
                    <table class="w-full">
                        <tbody class="divide-y">
                            <tr v-for="[method, path, text] in endpoints" :key="method + path">
                                <td class="w-20 py-1.5 font-mono text-xs font-semibold">{{ method }}</td>
                                <td class="py-1.5 font-mono text-xs">{{ path }}</td>
                                <td class="py-1.5 text-muted-foreground">{{ text }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'Add webhook' : 'Edit webhook' }}</DialogTitle>
                    <DialogDescription>The URL must be https and reachable from the internet.</DialogDescription>
                </DialogHeader>
                <form id="webhook-form" class="grid gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="wh-url">URL</Label>
                        <Input id="wh-url" v-model="form.url" type="url" required placeholder="https://example.com/cloudpbx" />
                        <InputError :message="form.errors.url" />
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">Events</legend>
                        <label v-for="(text, key) in events" :key="key" class="flex cursor-pointer items-start gap-3 text-sm">
                            <input v-model="form.events" type="checkbox" :value="key" class="mt-0.5 size-4" />
                            <span
                                ><span class="font-mono text-xs">{{ key }}</span
                                ><span class="block text-muted-foreground">{{ text }}</span></span
                            >
                        </label>
                        <InputError :message="form.errors.events" />
                    </fieldset>
                    <ToggleField v-model="form.active" label="Active" />
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="webhook-form" :disabled="form.processing"
                        ><LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            title="Delete this webhook?"
            description="Events stop being sent to this URL."
            confirm-label="Delete webhook"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
