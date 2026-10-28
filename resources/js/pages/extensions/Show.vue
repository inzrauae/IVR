<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ToggleField from '@/components/ToggleField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { humanizeAction } from '@/lib/icons';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, Copy, Eye, KeyRound, LoaderCircle, RefreshCw, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface ExtensionDetail {
    id: number;
    number: string;
    name: string;
    status: string;
    user_id: number | null;
    department_id: number | null;
    user: { id: number; name: string; email: string } | null;
    department: { id: number; name: string } | null;
    registered: boolean;
    provisioning_status: 'pending' | 'provisioned' | 'failed';
    provisioning_error: string | null;
    caller_id_name: string | null;
    caller_id_number: string | null;
    webrtc_enabled: boolean;
    voicemail_enabled: boolean;
    do_not_disturb: boolean;
    ring_timeout: number | null;
    forward_always: string | null;
    forward_busy: string | null;
    forward_no_answer: string | null;
    sip_username: string;
    registered_contact: string | null;
    registered_user_agent: string | null;
    registration_changed_at: string | null;
    provisioned_at: string | null;
}

interface CallRow {
    id: number;
    direction: string;
    status: string;
    from_number: string | null;
    from_name: string | null;
    to_number: string | null;
    started_at: string;
    duration: number | null;
}

const props = defineProps<{
    extension: ExtensionDetail;
    server: { sip_domain: string | null; wss_url: string | null } | null;
    departments: { id: number; name: string }[];
    users: { id: number; name: string }[];
    activity: {
        id: number;
        action: string;
        metadata: Record<string, unknown> | null;
        created_at: string;
        user: { id: number; name: string } | null;
    }[];
    recentCalls: CallRow[];
    defaultRingTimeout: number;
    can: { manage: boolean };
}>();

const page = usePage<SharedData>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Extensions', href: '/extensions' },
    { title: `${props.extension.number} · ${props.extension.name}`, href: `/extensions/${props.extension.id}` },
]);

const pick = (e: ExtensionDetail) => ({
    name: e.name,
    user_id: e.user_id,
    department_id: e.department_id,
    caller_id_name: e.caller_id_name ?? '',
    caller_id_number: e.caller_id_number ?? '',
    webrtc_enabled: e.webrtc_enabled,
    voicemail_enabled: e.voicemail_enabled,
    do_not_disturb: e.do_not_disturb,
    ring_timeout: (e.ring_timeout ?? '') as number | '',
    forward_always: e.forward_always ?? '',
    forward_busy: e.forward_busy ?? '',
    forward_no_answer: e.forward_no_answer ?? '',
    status: e.status,
});

const form = useForm(pick(props.extension));
const save = () =>
    form
        .transform((data) => ({
            ...data,
            ring_timeout: data.ring_timeout === '' ? null : data.ring_timeout,
            caller_id_name: data.caller_id_name || null,
            caller_id_number: data.caller_id_number || null,
            forward_always: data.forward_always || null,
            forward_busy: data.forward_busy || null,
            forward_no_answer: data.forward_no_answer || null,
        }))
        .put(route('extensions.update', { extension: props.extension.id }), {
            preserveScroll: true,
            onSuccess: () => form.defaults(pick(props.extension)),
        });

const setStatus = (status: string) => {
    form.status = status;
    save();
};

const busy = ref<string | null>(null);
const post = (name: string, routeName: string) => {
    busy.value = name;
    router.post(route(routeName, { extension: props.extension.id }), {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
};

const credentials = computed(() => page.props.flash.credentials);
const copied = ref<string | null>(null);
const copy = async (label: string, value: string) => {
    await navigator.clipboard.writeText(value);
    copied.value = label;
    setTimeout(() => (copied.value = null), 1500);
};

const confirmingRegenerate = ref(false);
const regenerate = () => {
    confirmingRegenerate.value = false;
    post('password', 'extensions.password');
};

const deleting = ref(false);
const destroy = () => router.delete(route('extensions.destroy', { extension: props.extension.id }));

// Follow a pending provisioning job until the PBX answers.
let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    poll = setInterval(() => {
        if (props.extension.provisioning_status === 'pending') {
            router.reload({ only: ['extension', 'activity'] });
        }
    }, 3000);
});
onBeforeUnmount(() => clearInterval(poll));

const formatTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const formatDuration = (s: number | null) => (s === null ? '—' : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`);
</script>

<template>
    <Head :title="`Extension ${extension.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-3 text-xl font-semibold tracking-tight">
                        <span class="font-mono">{{ extension.number }}</span>
                        <span>{{ extension.name }}</span>
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ extension.user ? `Assigned to ${extension.user.name}` : 'Shared phone' }}
                        <template v-if="extension.department"> · {{ extension.department.name }}</template>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge :status="extension.registered ? 'registered' : 'offline'" />
                    <StatusBadge :status="extension.status" />
                    <template v-if="can.manage">
                        <Button
                            v-if="extension.status === 'active'"
                            variant="outline"
                            size="sm"
                            :disabled="form.processing"
                            @click="setStatus('disabled')"
                        >
                            Disable
                        </Button>
                        <Button v-else variant="outline" size="sm" :disabled="form.processing" @click="setStatus('active')">Enable</Button>
                        <Button variant="ghost" size="icon" aria-label="Delete extension" @click="deleting = true">
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </template>
                </div>
            </div>

            <!-- Provisioning state -->
            <div
                v-if="extension.provisioning_status === 'failed'"
                class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm"
            >
                <div class="flex gap-2">
                    <CircleAlert class="mt-0.5 size-4 shrink-0 text-destructive" />
                    <div>
                        <p class="font-medium text-destructive">The phone system has not accepted this extension's latest settings.</p>
                        <p class="mt-0.5 text-muted-foreground">{{ extension.provisioning_error }}</p>
                    </div>
                </div>
                <Button v-if="can.manage" size="sm" variant="outline" :disabled="busy === 'retry'" @click="post('retry', 'extensions.provision')">
                    <RefreshCw class="size-4" :class="{ 'animate-spin': busy === 'retry' }" />
                    Retry
                </Button>
            </div>
            <div
                v-else-if="extension.provisioning_status === 'pending'"
                class="flex items-center gap-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm"
            >
                <LoaderCircle class="size-4 animate-spin text-muted-foreground" />
                Applying changes to the phone system…
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Settings -->
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="text-base">Settings</CardTitle>
                        <CardDescription>Changes are pushed to the phone system as soon as you save.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="space-y-6" @submit.prevent="save">
                            <fieldset :disabled="!can.manage" class="space-y-6">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="name">Display name</Label>
                                        <Input id="name" v-model="form.name" required />
                                        <InputError :message="form.errors.name" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="user">Assigned user</Label>
                                        <NativeSelect id="user" v-model="form.user_id">
                                            <option :value="null">Nobody (shared phone)</option>
                                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                        </NativeSelect>
                                        <InputError :message="form.errors.user_id" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="department">Department</Label>
                                        <NativeSelect id="department" v-model="form.department_id">
                                            <option :value="null">None</option>
                                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                                        </NativeSelect>
                                        <InputError :message="form.errors.department_id" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="ring_timeout">Ring timeout (seconds)</Label>
                                        <Input
                                            id="ring_timeout"
                                            v-model.number="form.ring_timeout"
                                            type="number"
                                            min="5"
                                            max="120"
                                            :placeholder="`${defaultRingTimeout} (organization default)`"
                                        />
                                        <InputError :message="form.errors.ring_timeout" />
                                    </div>
                                </div>

                                <div>
                                    <h3 class="mb-3 text-sm font-medium">Caller ID</h3>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div class="grid gap-2">
                                            <Label for="cid_name">Internal name</Label>
                                            <Input id="cid_name" v-model="form.caller_id_name" :placeholder="form.name" maxlength="64" />
                                            <InputError :message="form.errors.caller_id_name" />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="cid_number">External number</Label>
                                            <Input id="cid_number" v-model="form.caller_id_number" placeholder="Organization default" />
                                            <InputError :message="form.errors.caller_id_number" />
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <h3 class="mb-1 text-sm font-medium">Call forwarding</h3>
                                    <p class="mb-3 text-sm text-muted-foreground">
                                        An extension number or an external number. External forwarding needs an outbound route.
                                    </p>
                                    <div class="grid gap-4 sm:grid-cols-3">
                                        <div class="grid gap-2">
                                            <Label for="fwd_always">Always</Label>
                                            <Input id="fwd_always" v-model="form.forward_always" inputmode="tel" placeholder="Off" />
                                            <InputError :message="form.errors.forward_always" />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="fwd_busy">When busy</Label>
                                            <Input id="fwd_busy" v-model="form.forward_busy" inputmode="tel" placeholder="Off" />
                                            <InputError :message="form.errors.forward_busy" />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="fwd_na">When unanswered</Label>
                                            <Input id="fwd_na" v-model="form.forward_no_answer" inputmode="tel" placeholder="Off" />
                                            <InputError :message="form.errors.forward_no_answer" />
                                        </div>
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-3">
                                    <ToggleField
                                        v-model="form.do_not_disturb"
                                        label="Do not disturb"
                                        description="Callers go to voicemail or hear busy."
                                        :disabled="!can.manage"
                                    />
                                    <ToggleField
                                        v-model="form.voicemail_enabled"
                                        label="Voicemail"
                                        description="Take a message when unanswered."
                                        :disabled="!can.manage"
                                    />
                                    <ToggleField
                                        v-model="form.webrtc_enabled"
                                        label="Web phone"
                                        description="Allow browser (WebRTC) devices."
                                        :disabled="!can.manage"
                                    />
                                </div>
                            </fieldset>

                            <div v-if="can.manage" class="flex items-center gap-3">
                                <Button type="submit" :disabled="form.processing || !form.isDirty">
                                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                                    Save changes
                                </Button>
                                <span v-if="form.isDirty" class="text-sm text-muted-foreground">Unsaved changes</span>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-6">
                    <!-- Device connection -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Device setup</CardTitle>
                            <CardDescription>Use these details in a SIP phone or softphone. Up to 5 devices can register at once.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3 text-sm">
                            <dl class="space-y-2">
                                <div>
                                    <dt class="text-xs text-muted-foreground">SIP server</dt>
                                    <dd class="font-mono">{{ server?.sip_domain ?? 'Not assigned yet' }}</dd>
                                </div>
                                <div v-if="server?.wss_url">
                                    <dt class="text-xs text-muted-foreground">WebSocket (WebRTC)</dt>
                                    <dd class="break-all font-mono">{{ server.wss_url }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">Username</dt>
                                    <dd class="flex items-center gap-2 font-mono">
                                        {{ extension.sip_username }}
                                        <button
                                            type="button"
                                            class="text-muted-foreground hover:text-foreground"
                                            aria-label="Copy username"
                                            @click="copy('username', extension.sip_username)"
                                        >
                                            <Copy class="size-3.5" />
                                        </button>
                                        <span v-if="copied === 'username'" class="text-xs text-emerald-600">Copied</span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">Password</dt>
                                    <dd
                                        v-if="credentials && credentials.username === extension.sip_username"
                                        class="flex items-center gap-2 font-mono"
                                    >
                                        <span class="break-all">{{ credentials.password }}</span>
                                        <button
                                            type="button"
                                            class="text-muted-foreground hover:text-foreground"
                                            aria-label="Copy password"
                                            @click="copy('password', credentials.password)"
                                        >
                                            <Copy class="size-3.5" />
                                        </button>
                                        <span v-if="copied === 'password'" class="text-xs text-emerald-600">Copied</span>
                                    </dd>
                                    <dd v-else class="text-muted-foreground">••••••••••••</dd>
                                </div>
                            </dl>
                            <div v-if="can.manage" class="flex flex-wrap gap-2 pt-1">
                                <Button size="sm" variant="outline" :disabled="busy === 'reveal'" @click="post('reveal', 'extensions.credentials')">
                                    <Eye class="size-4" />
                                    Show password
                                </Button>
                                <Button size="sm" variant="outline" :disabled="busy === 'password'" @click="confirmingRegenerate = true">
                                    <KeyRound class="size-4" />
                                    New password
                                </Button>
                            </div>
                            <p v-if="can.manage" class="text-xs text-muted-foreground">Viewing the password is recorded in the activity log.</p>
                        </CardContent>
                    </Card>

                    <!-- Registration -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Registration</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-2 text-sm">
                            <p v-if="extension.registered">
                                Registered from <span class="font-mono text-xs">{{ extension.registered_contact }}</span>
                                <span v-if="extension.registered_user_agent" class="block text-xs text-muted-foreground">{{
                                    extension.registered_user_agent
                                }}</span>
                            </p>
                            <p v-else class="text-muted-foreground">No device is registered.</p>
                            <p class="text-xs text-muted-foreground">Last change: {{ formatTime(extension.registration_changed_at) }}</p>
                            <p class="text-xs text-muted-foreground">Last provisioned: {{ formatTime(extension.provisioned_at) }}</p>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Recent calls</CardTitle>
                    </CardHeader>
                    <CardContent class="p-0">
                        <table v-if="recentCalls.length" class="w-full text-sm">
                            <tbody class="divide-y">
                                <tr v-for="call in recentCalls" :key="call.id">
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium">{{ call.from_name ?? call.from_number }} → {{ call.to_number ?? '—' }}</div>
                                        <div class="text-xs capitalize text-muted-foreground">
                                            {{ call.direction }} · {{ formatTime(call.started_at) }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatDuration(call.duration) }}</td>
                                    <td class="px-4 py-2.5 text-right"><StatusBadge :status="call.status" /></td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="px-6 pb-6 text-sm text-muted-foreground">No calls yet.</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Activity</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol v-if="activity.length" class="space-y-3 text-sm">
                            <li v-for="entry in activity" :key="entry.id" class="flex justify-between gap-4">
                                <div>
                                    <p>{{ humanizeAction(entry.action) }}</p>
                                    <p v-if="entry.metadata?.error" class="text-xs text-destructive">{{ entry.metadata.error }}</p>
                                    <p class="text-xs text-muted-foreground">{{ entry.user?.name ?? 'System' }}</p>
                                </div>
                                <time class="shrink-0 text-xs text-muted-foreground">{{ formatTime(entry.created_at) }}</time>
                            </li>
                        </ol>
                        <p v-else class="text-sm text-muted-foreground">No activity recorded.</p>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Dialog :open="confirmingRegenerate" @update:open="confirmingRegenerate = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Generate a new SIP password?</DialogTitle>
                    <DialogDescription>Every device using the current password will stop registering until it is updated.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="confirmingRegenerate = false">Cancel</Button>
                    <Button @click="regenerate">Generate password</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="deleting" @update:open="deleting = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete extension {{ extension.number }}?</DialogTitle>
                    <DialogDescription>Its devices are disconnected and the number becomes free. Call history is kept.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleting = false">Cancel</Button>
                    <Button variant="destructive" @click="destroy">Delete extension</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
