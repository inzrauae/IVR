<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatDuration } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import type { Paginated } from '@/types/calls';
import { Head, Link, router } from '@inertiajs/vue3';
import { Mail, MailOpen, Phone, Play, Trash2 } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

interface Message {
    id: number;
    caller_number: string | null;
    caller_name: string | null;
    duration: number | null;
    heard: boolean;
    created_at: string;
    mailbox: { id: number; number: string; name: string } | null;
}

const props = defineProps<{
    messages: Paginated<Message>;
    unheard: number;
    mailboxes: { id: number; number: string; name: string }[];
    myMailbox: { id: number; number: string; name: string; voicemail_enabled: boolean } | null;
    filters: { mailbox: number | null; filter: string | null };
    can: { manage: boolean; call: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Voicemail', href: '/voicemail' }];

const filters = reactive({ ...props.filters });
watch(filters, () =>
    router.get(route('voicemail.index'), Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), { preserveState: true, replace: true }),
);

const playing = ref<number | null>(null);
const play = (m: Message) => {
    playing.value = m.id;
    m.heard = true;
};
const toggleHeard = (m: Message) => router.patch(route('voicemail.update', { message: m.id }), { heard: !m.heard }, { preserveScroll: true });

const removing = ref<Message | null>(null);
const remove = () => {
    if (removing.value)
        router.delete(route('voicemail.destroy', { message: removing.value.id }), { preserveScroll: true, onFinish: () => (removing.value = null) });
};
</script>

<template>
    <Head title="Voicemail" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader
                title="Voicemail"
                :description="
                    unheard
                        ? `${unheard} new message${unheard === 1 ? '' : 's'}`
                        : can.manage
                          ? 'Messages in every mailbox.'
                          : 'Messages left on your extension.'
                "
            >
                <NativeSelect v-if="can.manage" v-model="filters.mailbox" class="w-56" aria-label="Mailbox">
                    <option :value="null">All mailboxes</option>
                    <option v-for="m in mailboxes" :key="m.id" :value="m.id">{{ m.number }} · {{ m.name }}</option>
                </NativeSelect>
                <NativeSelect v-model="filters.filter" class="w-36" aria-label="Show">
                    <option :value="null">All messages</option>
                    <option value="new">New only</option>
                </NativeSelect>
            </PageHeader>

            <div
                v-if="!can.manage && (!myMailbox || !myMailbox.voicemail_enabled)"
                class="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground"
            >
                {{
                    myMailbox
                        ? `Voicemail is turned off for extension ${myMailbox.number}.`
                        : 'You do not have an extension, so there is no mailbox for you yet.'
                }}
            </div>

            <Card>
                <CardContent class="p-0">
                    <ul class="divide-y">
                        <li
                            v-for="m in messages.data"
                            :key="m.id"
                            class="flex flex-wrap items-center gap-4 px-4 py-3 text-sm"
                            :class="{ 'bg-sky-500/5': !m.heard }"
                        >
                            <span
                                class="size-2 rounded-full"
                                :class="m.heard ? 'bg-transparent' : 'bg-sky-500'"
                                :aria-label="m.heard ? 'Heard' : 'New'"
                            />
                            <div class="min-w-0 flex-1">
                                <div :class="m.heard ? '' : 'font-semibold'">{{ m.caller_name ?? m.caller_number ?? 'Unknown caller' }}</div>
                                <div class="text-xs text-muted-foreground">
                                    <span v-if="m.caller_name">{{ m.caller_number }} · </span>{{ formatDateTime(m.created_at) }} ·
                                    {{ formatDuration(m.duration) }}
                                    <span v-if="can.manage && m.mailbox"> · for {{ m.mailbox.number }} {{ m.mailbox.name }}</span>
                                </div>
                            </div>
                            <audio
                                v-if="playing === m.id"
                                controls
                                autoplay
                                class="h-9 w-full sm:w-72"
                                :src="route('voicemail.play', { message: m.id })"
                            />
                            <Button v-else variant="outline" size="sm" @click="play(m)"><Play class="size-3.5" /> Play</Button>
                            <Button
                                v-if="can.call && m.caller_number"
                                variant="ghost"
                                size="icon"
                                as="a"
                                :href="route('web-phone.index', { dial: m.caller_number })"
                                :aria-label="`Call back ${m.caller_number}`"
                            >
                                <Phone class="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" :aria-label="m.heard ? 'Mark as new' : 'Mark as heard'" @click="toggleHeard(m)">
                                <Mail v-if="m.heard" class="size-4" />
                                <MailOpen v-else class="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" aria-label="Delete message" @click="removing = m"
                                ><Trash2 class="size-4 text-destructive"
                            /></Button>
                        </li>
                        <li v-if="!messages.data.length" class="px-4 py-12 text-center text-muted-foreground">
                            No messages.
                            <Link v-if="filters.filter" :href="route('voicemail.index')" class="underline">Show all</Link>
                        </li>
                    </ul>
                    <Pagination :page="messages" />
                </CardContent>
            </Card>
        </div>

        <ConfirmDialog
            :open="removing !== null"
            title="Delete this voicemail?"
            description="The message is removed from the phone system and cannot be recovered."
            confirm-label="Delete message"
            @confirm="remove"
            @cancel="removing = null"
        />
    </AppLayout>
</template>
