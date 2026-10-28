<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div :class="cn('rounded-2xl border border-border/70 bg-card text-card-foreground shadow-[0_4px_24px_-12px_rgb(15_23_42_/_0.16)]', props.class)">
        <slot />
    </div>
</template>
