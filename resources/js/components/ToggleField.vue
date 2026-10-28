<script setup lang="ts">
defineProps<{ label: string; description?: string; disabled?: boolean }>();

const model = defineModel<boolean>({ required: true });
</script>

<template>
    <label class="flex cursor-pointer items-start justify-between gap-6 rounded-lg border p-4" :class="{ 'cursor-not-allowed opacity-60': disabled }">
        <span>
            <span class="block text-sm font-medium">{{ label }}</span>
            <span v-if="description" class="mt-0.5 block text-sm text-muted-foreground">{{ description }}</span>
        </span>
        <span class="relative mt-0.5 inline-flex shrink-0">
            <input v-model="model" type="checkbox" class="peer sr-only" :disabled="disabled" />
            <span
                class="h-5 w-9 rounded-full bg-input transition-colors peer-checked:bg-primary peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2"
            />
            <span class="absolute left-0.5 top-0.5 size-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
        </span>
    </label>
</template>
