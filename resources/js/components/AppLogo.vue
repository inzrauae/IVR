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
    <div class="flex aspect-square size-8 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-cyan-500 text-primary-foreground">
        <AudioLines class="size-5" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="truncate font-semibold leading-tight">{{ page.props.name }}</span>
        <span class="truncate text-xs text-muted-foreground">{{ page.props.organization?.name ?? 'Platform console' }}</span>
    </div>
</template>
