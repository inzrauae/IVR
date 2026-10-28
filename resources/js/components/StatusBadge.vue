<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string | null | undefined }>();

const tones: Record<string, string> = {
    active: 'bg-emerald-500/10 text-emerald-700 ring-emerald-600/20 dark:text-emerald-400',
    online: 'bg-emerald-500/10 text-emerald-700 ring-emerald-600/20 dark:text-emerald-400',
    trial: 'bg-sky-500/10 text-sky-700 ring-sky-600/20 dark:text-sky-400',
    trialing: 'bg-sky-500/10 text-sky-700 ring-sky-600/20 dark:text-sky-400',
    past_due: 'bg-amber-500/10 text-amber-700 ring-amber-600/20 dark:text-amber-400',
    suspended: 'bg-red-500/10 text-red-700 ring-red-600/20 dark:text-red-400',
    offline: 'bg-red-500/10 text-red-700 ring-red-600/20 dark:text-red-400',
    disabled: 'bg-red-500/10 text-red-700 ring-red-600/20 dark:text-red-400',
    paid: 'bg-emerald-500/10 text-emerald-700 ring-emerald-600/20 dark:text-emerald-400',
    open: 'bg-sky-500/10 text-sky-700 ring-sky-600/20 dark:text-sky-400',
    overdue: 'bg-red-500/10 text-red-700 ring-red-600/20 dark:text-red-400',
    cancelled: 'bg-muted text-muted-foreground ring-border',
};

const tone = computed(() => tones[props.status ?? ''] ?? 'bg-muted text-muted-foreground ring-border');
const label = computed(() => (props.status ?? 'unknown').replace(/_/g, ' '));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset" :class="tone">
        <span class="size-1.5 rounded-full bg-current" />
        {{ label }}
    </span>
</template>
