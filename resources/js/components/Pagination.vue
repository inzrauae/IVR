<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    page: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
</script>

<template>
    <div v-if="page.total > 0" class="flex items-center justify-between gap-4 border-t px-4 py-3 text-sm text-muted-foreground">
        <span>{{ page.from }}–{{ page.to }} of {{ page.total }}</span>
        <div class="flex gap-2">
            <component
                :is="page.prev_page_url ? Link : 'span'"
                :href="page.prev_page_url ?? undefined"
                preserve-scroll
                class="rounded-md border px-3 py-1"
                :class="page.prev_page_url ? 'hover:bg-muted' : 'opacity-40'"
                >Previous</component
            >
            <component
                :is="page.next_page_url ? Link : 'span'"
                :href="page.next_page_url ?? undefined"
                preserve-scroll
                class="rounded-md border px-3 py-1"
                :class="page.next_page_url ? 'hover:bg-muted' : 'opacity-40'"
                >Next</component
            >
        </div>
    </div>
</template>
