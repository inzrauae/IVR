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
    <Card class="metric-card relative overflow-hidden transition-shadow hover:shadow-md">
        <CardContent class="flex items-start justify-between gap-4 p-5 md:p-6">
            <div class="min-w-0">
                <p class="text-xs font-medium text-muted-foreground">{{ label }}</p>
                <p class="mt-3 break-words text-3xl font-semibold tabular-nums tracking-tight" :class="{ 'text-muted-foreground': value === null }">
                    {{ value ?? '—' }}
                </p>
                <p v-if="hint" class="mt-2 text-xs leading-relaxed text-muted-foreground">{{ hint }}</p>
            </div>
            <div class="shrink-0 rounded-xl bg-primary/10 p-2.5 text-primary">
                <component :is="icon" class="size-5" />
            </div>
        </CardContent>
    </Card>
</template>
