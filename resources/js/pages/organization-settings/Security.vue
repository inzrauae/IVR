<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OrganizationSettingsLayout from '@/layouts/OrganizationSettingsLayout.vue';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, KeyRound, LoaderCircle, Monitor, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    section: string;
    canManage: boolean;
    settings: { ip_allowlist: string[] };
    currentIp: string;
    tokens: { id: number; name: string; last_used_at: string | null; created_at: string }[];
    sessions: { ip_address: string; user_agent: string; last_active_at: string; is_current: boolean }[];
    sessionsSupported: boolean;
    logins: { id: number; ip_address: string | null; user_agent: string | null; created_at: string; user: { name: string } | null }[];
}>();

const page = usePage<SharedData>();
const newToken = computed(() => page.props.flash.token ?? null);

// IP allowlist
const allowlistText = ref(props.settings.ip_allowlist.join('\n'));
const allowlistForm = useForm({ ip_allowlist: [] as string[] });
const saveAllowlist = () => {
    allowlistForm.ip_allowlist = allowlistText.value
        .split(/[\n,]+/)
        .map((s) => s.trim())
        .filter(Boolean);
    allowlistForm.put(route('organization-settings.security.update'), { preserveScroll: true });
};
const allowlistError = computed(() => {
    const errors = allowlistForm.errors as Record<string, string>;
    return errors.ip_allowlist ?? Object.entries(errors).find(([k]) => k.startsWith('ip_allowlist.'))?.[1];
});
const addMyIp = () => {
    if (
        !allowlistText.value
            .split('\n')
            .map((s) => s.trim())
            .includes(props.currentIp)
    ) {
        allowlistText.value = (allowlistText.value.trim() ? allowlistText.value.trim() + '\n' : '') + props.currentIp;
    }
};

// API tokens
const tokenForm = useForm({ name: '' });
const createToken = () => tokenForm.post(route('organization-settings.tokens.store'), { preserveScroll: true, onSuccess: () => tokenForm.reset() });
const revokeToken = (id: number) => router.delete(route('organization-settings.tokens.destroy', { token: id }), { preserveScroll: true });
const copied = ref(false);
const copyToken = async () => {
    if (!newToken.value) return;
    await navigator.clipboard.writeText(newToken.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
};

// Sessions
const confirmingLogout = ref(false);
const sessionForm = useForm({ password: '' });
const logoutOthers = () =>
    sessionForm.delete(route('organization-settings.sessions.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            confirmingLogout.value = false;
            sessionForm.reset();
        },
    });

const describeAgent = (ua: string | null) => {
    if (!ua) return 'Unknown device';
    const browser = /Edg\//.test(ua)
        ? 'Edge'
        : /Chrome\//.test(ua)
          ? 'Chrome'
          : /Firefox\//.test(ua)
            ? 'Firefox'
            : /Safari\//.test(ua)
              ? 'Safari'
              : 'Browser';
    const os = /Windows/.test(ua)
        ? 'Windows'
        : /Mac OS/.test(ua)
          ? 'macOS'
          : /Android/.test(ua)
            ? 'Android'
            : /iPhone|iPad/.test(ua)
              ? 'iOS'
              : /Linux/.test(ua)
                ? 'Linux'
                : '';
    return os ? `${browser} on ${os}` : browser;
};
const formatTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'Never');
</script>

<template>
    <Head title="Security" />

    <OrganizationSettingsLayout
        :section="section"
        title="Security"
        description="Network restrictions, API access and sign-in activity"
        :can-manage="canManage"
    >
        <div class="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">IP allowlist</CardTitle>
                    <CardDescription>
                        When set, members can only use the portal and API from these addresses. One IP or CIDR range per line. Leave empty to allow
                        all.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="space-y-3" @submit.prevent="saveAllowlist">
                        <textarea
                            v-model="allowlistText"
                            rows="4"
                            :disabled="!canManage"
                            placeholder="203.0.113.10&#10;198.51.100.0/24"
                            aria-label="Allowed IP addresses"
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        />
                        <InputError :message="allowlistError" />
                        <div v-if="canManage" class="flex flex-wrap items-center gap-3">
                            <Button type="submit" :disabled="allowlistForm.processing">Save allowlist</Button>
                            <Button type="button" variant="outline" size="sm" @click="addMyIp">Add my IP ({{ currentIp }})</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">API tokens</CardTitle>
                    <CardDescription
                        >Personal tokens for the REST API (<code class="text-xs">/api/v1</code>). They act with your permissions.</CardDescription
                    >
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="newToken" class="space-y-2 rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-4">
                        <p class="text-sm font-medium">Copy your new token now. It won't be shown again.</p>
                        <div class="flex gap-2">
                            <code class="flex-1 overflow-x-auto whitespace-nowrap rounded bg-background px-3 py-2 font-mono text-xs">{{
                                newToken
                            }}</code>
                            <Button variant="outline" size="sm" @click="copyToken"><Copy class="size-4" /> {{ copied ? 'Copied' : 'Copy' }}</Button>
                        </div>
                    </div>

                    <form v-if="canManage" class="flex gap-2" @submit.prevent="createToken">
                        <Input v-model="tokenForm.name" placeholder="Token name, e.g. CRM sync" aria-label="Token name" required />
                        <Button type="submit" :disabled="tokenForm.processing"><KeyRound class="size-4" /> Create</Button>
                    </form>
                    <InputError :message="tokenForm.errors.name" />

                    <ul v-if="tokens.length" class="divide-y rounded-lg border">
                        <li v-for="t in tokens" :key="t.id" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <span>
                                <span class="font-medium">{{ t.name }}</span>
                                <span class="block text-xs text-muted-foreground"
                                    >Created {{ formatTime(t.created_at) }} · Last used {{ formatTime(t.last_used_at) }}</span
                                >
                            </span>
                            <Button variant="ghost" size="icon" :aria-label="`Revoke ${t.name}`" @click="revokeToken(t.id)">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">You have no API tokens.</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex-row items-start justify-between gap-4 space-y-0">
                    <div>
                        <CardTitle class="text-base">Your active sessions</CardTitle>
                        <CardDescription>Browsers signed in to your account</CardDescription>
                    </div>
                    <Button v-if="sessionsSupported && sessions.length > 1" variant="outline" size="sm" @click="confirmingLogout = true">
                        Sign out other sessions
                    </Button>
                </CardHeader>
                <CardContent>
                    <ul v-if="sessionsSupported" class="divide-y">
                        <li v-for="(s, i) in sessions" :key="i" class="flex items-center gap-3 py-2.5 text-sm">
                            <Monitor class="size-5 text-muted-foreground" />
                            <span class="flex-1">
                                {{ describeAgent(s.user_agent) }}
                                <span class="block text-xs text-muted-foreground">{{ s.ip_address }} · {{ formatTime(s.last_active_at) }}</span>
                            </span>
                            <span v-if="s.is_current" class="text-xs font-medium text-emerald-600">This device</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">Session listing requires the database session driver.</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Recent sign-ins</CardTitle>
                    <CardDescription>Last 15 logins across your organization</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto p-0">
                    <table v-if="logins.length" class="w-full text-sm">
                        <thead class="border-y bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 font-medium">Device</th>
                                <th class="px-4 py-2 font-medium">IP</th>
                                <th class="px-4 py-2 font-medium">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="l in logins" :key="l.id">
                                <td class="px-4 py-2">{{ l.user?.name ?? 'Deleted user' }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ describeAgent(l.user_agent) }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ l.ip_address }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ formatTime(l.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-6 pb-6 text-sm text-muted-foreground">No sign-ins recorded yet.</p>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="confirmingLogout" @update:open="confirmingLogout = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Sign out other sessions</DialogTitle>
                    <DialogDescription>Enter your password to confirm. This device stays signed in.</DialogDescription>
                </DialogHeader>
                <form id="logout-others" class="grid gap-2" @submit.prevent="logoutOthers">
                    <Label for="session-password">Password</Label>
                    <Input id="session-password" v-model="sessionForm.password" type="password" autocomplete="current-password" required />
                    <InputError :message="sessionForm.errors.password" />
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="confirmingLogout = false">Cancel</Button>
                    <Button type="submit" form="logout-others" :disabled="sessionForm.processing">
                        <LoaderCircle v-if="sessionForm.processing" class="size-4 animate-spin" />
                        Sign out others
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </OrganizationSettingsLayout>
</template>
