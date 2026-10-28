<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import type { Component } from 'vue';

defineProps<{
    label: string;
    value: string | number | null;
    icon: Component;
    hint?: string;
}>();
</script>

<template>
    <Card>
        <CardContent class="flex items-start justify-between gap-4 p-5">
            <div class="min-w-0">
                <p class="text-sm text-muted-foreground">{{ label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight" :class="{ 'text-muted-foreground': value === null }">
                    {{ value ?? '—' }}
                </p>
                <p v-if="hint" class="mt-1 truncate text-xs text-muted-foreground">{{ hint }}</p>
            </div>
            <div class="rounded-lg bg-primary/10 p-2 text-primary">
                <component :is="icon" class="size-5" />
            </div>
        </CardContent>
    </Card>
</template>
