<script setup lang="ts">
import CallDirectionIcon from '@/components/CallDirectionIcon.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDuration, formatRelative } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import type { CallRow } from '@/types/calls';
import { Head } from '@inertiajs/vue3';
import { Delete, Mic, MicOff, Pause, Phone, PhoneIncoming, PhoneOff, Play, Plug, Search, TriangleAlert } from 'lucide-vue-next';
import type { SimpleUser } from 'sip.js/lib/platform/web';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    extension: { id: number; number: string; name: string; webrtc_enabled: boolean; provisioning_status: string; status: string } | null;
    server: { wss_url: string | null; sip_domain: string | null; simulated: boolean } | null;
    recentCalls: CallRow[];
    contacts: { id: number; name: string; company: string | null; phone: string | null; mobile: string | null }[];
    dial: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Web Phone', href: '/web-phone' }];

// Why the phone cannot connect, if it cannot.
const blocker = computed(() => {
    if (!props.extension) return 'You do not have an extension yet. Ask an administrator to assign one to you.';
    if (props.extension.status !== 'active') return `Your extension ${props.extension.number} is disabled.`;
    if (!props.extension.webrtc_enabled)
        return `The web phone is turned off for extension ${props.extension.number}. An administrator can enable it on the extension page.`;
    if (!props.server) return 'No PBX server is assigned to your organization yet.';
    if (props.server.simulated)
        return 'This organization uses the PBX simulator, which has no real audio. The web phone works once a real Asterisk server is assigned.';
    if (!props.server.wss_url || !props.server.sip_domain)
        return 'The PBX server has no WebSocket (WSS) address configured. A platform administrator can add it.';
    return null;
});

type LineState = 'offline' | 'connecting' | 'ready' | 'error';
type CallState = 'idle' | 'calling' | 'incoming' | 'active';

const line = ref<LineState>('offline');
const call = ref<CallState>('idle');
const error = ref<string | null>(null);
const number = ref(props.dial ?? '');
const peer = ref('');
const muted = ref(false);
const held = ref(false);
const startedAt = ref<number | null>(null);
const now = ref(Date.now());
const remoteAudio = ref<HTMLAudioElement | null>(null);

let phone: SimpleUser | null = null;
const timer = setInterval(() => (now.value = Date.now()), 1000);

const resetCall = () => {
    call.value = 'idle';
    muted.value = false;
    held.value = false;
    startedAt.value = null;
    peer.value = '';
};

async function connect() {
    if (blocker.value || line.value === 'connecting') return;
    line.value = 'connecting';
    error.value = null;

    try {
        const response = await fetch(route('web-phone.credentials'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const creds = await response.json();
        if (!response.ok) throw new Error(creds.message ?? 'Could not load your phone credentials.');

        const { SimpleUser } = await import('sip.js/lib/platform/web');
        phone = new SimpleUser(creds.wss_url, {
            aor: `sip:${creds.username}@${creds.domain}`,
            media: { constraints: { audio: true, video: false }, remote: { audio: remoteAudio.value ?? undefined } },
            userAgentOptions: { authorizationUsername: creds.username, authorizationPassword: creds.password, displayName: creds.display_name },
            reconnectionAttempts: 5,
            delegate: {
                onRegistered: () => (line.value = 'ready'),
                onUnregistered: () => line.value !== 'offline' && (line.value = 'connecting'),
                onServerDisconnect: (e?: Error) => {
                    line.value = 'error';
                    error.value = e ? `Lost connection to the PBX: ${e.message}` : 'Disconnected from the PBX.';
                },
                onCallReceived: () => {
                    // SimpleUser does not expose the caller; read it from the underlying session.
                    const session = (phone as unknown as { session?: { remoteIdentity?: { uri: { user?: string }; displayName?: string } } })
                        ?.session;
                    peer.value = session?.remoteIdentity?.displayName || session?.remoteIdentity?.uri.user || 'Unknown caller';
                    call.value = 'incoming';
                },
                onCallAnswered: () => {
                    call.value = 'active';
                    startedAt.value = Date.now();
                },
                onCallHangup: resetCall,
                onCallHold: (isHeld: boolean) => (held.value = isHeld),
            },
        });

        await phone.connect();
        await phone.register();
    } catch (e) {
        line.value = 'error';
        error.value = e instanceof Error ? e.message : String(e);
    }
}

async function disconnect() {
    try {
        await phone?.unregister();
        await phone?.disconnect();
    } finally {
        phone = null;
        line.value = 'offline';
        resetCall();
    }
}

async function place(target?: string) {
    const to = (target ?? number.value).replace(/[^\d+*#]/g, '');
    if (!to || !phone || line.value !== 'ready' || call.value !== 'idle') return;
    number.value = to;
    peer.value = to;
    call.value = 'calling';
    try {
        await phone.call(`sip:${to}@${(props.server?.sip_domain ?? '').trim()}`);
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
        resetCall();
    }
}

const answer = () => phone?.answer().catch((e) => (error.value = String(e)));
const decline = () => phone?.decline().finally(resetCall);
const hangup = () => phone?.hangup().finally(resetCall);
const toggleMute = () => {
    if (!phone) return;
    if (muted.value) phone.unmute();
    else phone.mute();
    muted.value = phone.isMuted();
};
const toggleHold = () => (held.value ? phone?.unhold() : phone?.hold());

const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];
const press = (key: string) => {
    if (call.value === 'active') phone?.sendDTMF(key);
    else number.value += key;
};

const search = ref('');
const matches = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = q ? props.contacts.filter((c) => [c.name, c.company, c.phone, c.mobile].some((v) => v?.toLowerCase().includes(q))) : props.contacts;
    return list.slice(0, 8);
});

const elapsed = computed(() => (startedAt.value ? formatDuration((now.value - startedAt.value) / 1000) : null));
const other = (c: CallRow) =>
    c.from_number === props.extension?.number ? { number: c.to_number, name: c.to_name } : { number: c.from_number, name: c.from_name };

onBeforeUnmount(() => {
    clearInterval(timer);
    if (phone) disconnect();
});
</script>

<template>
    <Head title="Web Phone" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader
                title="Web Phone"
                :description="extension ? `Extension ${extension.number} · ${extension.name}` : 'Make and take calls in your browser.'"
            >
                <StatusBadge
                    :status="line === 'ready' ? 'registered' : line === 'connecting' ? 'pending' : line === 'error' ? 'failed' : 'offline'"
                />
                <Button v-if="line === 'offline' || line === 'error'" :disabled="!!blocker" @click="connect">
                    <Plug class="size-4" /> Connect
                </Button>
                <Button v-else variant="outline" @click="disconnect">Disconnect</Button>
            </PageHeader>

            <div
                v-if="blocker"
                class="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-300"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                <p>{{ blocker }}</p>
            </div>
            <div v-if="error" class="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                <p>{{ error }}</p>
            </div>

            <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
                <Card>
                    <CardContent class="flex flex-col gap-4 p-5">
                        <div v-if="call === 'incoming'" class="rounded-lg bg-sky-500/10 p-4 text-center">
                            <PhoneIncoming class="mx-auto size-6 animate-pulse text-sky-600" />
                            <p class="mt-2 text-sm text-muted-foreground">Incoming call</p>
                            <p class="text-lg font-semibold">{{ peer }}</p>
                            <div class="mt-3 flex justify-center gap-2">
                                <Button class="bg-emerald-600 hover:bg-emerald-700" @click="answer"><Phone class="size-4" /> Answer</Button>
                                <Button variant="destructive" @click="decline"><PhoneOff class="size-4" /> Decline</Button>
                            </div>
                        </div>

                        <div v-else-if="call !== 'idle'" class="rounded-lg bg-muted/50 p-4 text-center">
                            <p class="text-sm text-muted-foreground">{{ call === 'calling' ? 'Calling…' : held ? 'On hold' : 'Connected' }}</p>
                            <p class="text-lg font-semibold">{{ peer }}</p>
                            <p class="font-mono text-sm tabular-nums">{{ elapsed ?? '' }}</p>
                        </div>

                        <div class="relative">
                            <Input
                                v-model="number"
                                class="h-12 pr-10 text-center font-mono text-xl tracking-wider"
                                placeholder="Number or extension"
                                aria-label="Number to dial"
                                inputmode="tel"
                                @keydown.enter="place()"
                            />
                            <button
                                v-if="number && call === 'idle'"
                                class="absolute right-2 top-3 text-muted-foreground hover:text-foreground"
                                aria-label="Delete last digit"
                                @click="number = number.slice(0, -1)"
                            >
                                <Delete class="size-5" />
                            </button>
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="key in keys"
                                :key="key"
                                class="h-12 rounded-lg border text-lg font-medium transition-colors hover:bg-muted active:bg-muted/70"
                                @click="press(key)"
                            >
                                {{ key }}
                            </button>
                        </div>

                        <div class="flex justify-center gap-2">
                            <Button
                                v-if="call === 'idle'"
                                class="h-12 flex-1 bg-emerald-600 hover:bg-emerald-700"
                                :disabled="line !== 'ready' || !number"
                                @click="place()"
                            >
                                <Phone class="size-5" /> Call
                            </Button>
                            <template v-else-if="call !== 'incoming'">
                                <Button variant="outline" size="icon" class="size-12" :aria-label="muted ? 'Unmute' : 'Mute'" @click="toggleMute">
                                    <MicOff v-if="muted" class="size-5 text-destructive" />
                                    <Mic v-else class="size-5" />
                                </Button>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="size-12"
                                    :disabled="call !== 'active'"
                                    :aria-label="held ? 'Resume' : 'Hold'"
                                    @click="toggleHold"
                                >
                                    <Play v-if="held" class="size-5" />
                                    <Pause v-else class="size-5" />
                                </Button>
                                <Button variant="destructive" class="h-12 flex-1" @click="hangup"><PhoneOff class="size-5" /> Hang up</Button>
                            </template>
                        </div>
                        <p v-if="line === 'offline' && !blocker" class="text-center text-xs text-muted-foreground">
                            Connect to start making and receiving calls.
                        </p>
                    </CardContent>
                </Card>

                <div class="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Recent calls</CardTitle>
                            <CardDescription>On your extension</CardDescription>
                        </CardHeader>
                        <CardContent class="p-0">
                            <ul class="divide-y text-sm">
                                <li v-for="c in recentCalls" :key="c.id" class="flex items-center gap-3 px-6 py-2.5">
                                    <CallDirectionIcon :direction="c.direction" :status="c.status" />
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium">{{ other(c).name ?? other(c).number ?? 'Unknown' }}</div>
                                        <div class="text-xs text-muted-foreground">{{ formatRelative(c.started_at) }}</div>
                                    </div>
                                    <Button
                                        v-if="other(c).number"
                                        variant="ghost"
                                        size="icon"
                                        :disabled="line !== 'ready' || call !== 'idle'"
                                        :aria-label="`Call ${other(c).number}`"
                                        @click="place(other(c).number!)"
                                    >
                                        <Phone class="size-4" />
                                    </Button>
                                </li>
                                <li v-if="!recentCalls.length" class="px-6 py-8 text-center text-muted-foreground">No calls yet.</li>
                            </ul>
                        </CardContent>
                    </Card>

                    <Card v-if="contacts.length">
                        <CardHeader>
                            <CardTitle class="text-base">Contacts</CardTitle>
                            <div class="relative pt-2">
                                <Search class="absolute left-2.5 top-[1.125rem] size-4 text-muted-foreground" />
                                <Input v-model="search" class="pl-8" placeholder="Search contacts" aria-label="Search contacts" />
                            </div>
                        </CardHeader>
                        <CardContent class="p-0">
                            <ul class="divide-y text-sm">
                                <li v-for="c in matches" :key="c.id" class="flex items-center gap-3 px-6 py-2.5">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium">{{ c.name }}</div>
                                        <div class="truncate text-xs text-muted-foreground">{{ c.company ?? c.phone ?? c.mobile }}</div>
                                    </div>
                                    <Button
                                        v-for="n in [c.phone, c.mobile].filter(Boolean)"
                                        :key="n!"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="line !== 'ready' || call !== 'idle'"
                                        @click="place(n!)"
                                    >
                                        <Phone class="size-3.5" /> {{ n }}
                                    </Button>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <audio ref="remoteAudio" autoplay class="hidden" />
        </div>
    </AppLayout>
</template>
