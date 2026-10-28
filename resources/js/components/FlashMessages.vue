<script setup lang="ts">
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const page = usePage<SharedData>();
const visible = ref<{ type: 'success' | 'error'; message: string } | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => page.props.flash,
    (flash) => {
        const message = flash?.error ?? flash?.success;
        if (!message) return;

        visible.value = { type: flash.error ? 'error' : 'success', message };
        clearTimeout(timer);
        timer = setTimeout(() => (visible.value = null), 6000);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <Transition
        enter-active-class="transition duration-200"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-150"
        leave-to-class="opacity-0"
    >
        <div
            v-if="visible"
            role="status"
            class="fixed bottom-4 right-4 z-50 flex max-w-sm items-start gap-3 rounded-lg border bg-card p-4 text-sm shadow-lg"
            :class="visible.type === 'error' ? 'border-destructive/40' : 'border-emerald-500/40'"
        >
            <CircleAlert v-if="visible.type === 'error'" class="mt-0.5 size-4 shrink-0 text-destructive" />
            <CircleCheck v-else class="mt-0.5 size-4 shrink-0 text-emerald-600" />
            <p class="flex-1">{{ visible.message }}</p>
            <button type="button" class="text-muted-foreground hover:text-foreground" aria-label="Dismiss" @click="visible = null">
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
