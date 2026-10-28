<script setup lang="ts">
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { AudioLines } from 'lucide-vue-next';

interface Props {
    class?: string;
}

defineProps<Props>();

const page = usePage<SharedData>();
</script>

<template>
    <div class="flex aspect-square size-9 items-center justify-center rounded-xl bg-primary text-white shadow-lg shadow-blue-500/20">
        <AudioLines class="size-5" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="truncate text-base font-semibold leading-tight tracking-tight text-sidebar-accent-foreground">{{ page.props.name }}</span>
        <span class="truncate text-xs text-muted-foreground">{{ page.props.organization?.name ?? 'Platform console' }}</span>
    </div>
</template>
