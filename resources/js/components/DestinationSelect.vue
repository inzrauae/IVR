<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { computed, ref, watch } from 'vue';

export interface DestinationOption {
    value: string;
    label: string;
    group: string;
}

const props = defineProps<{ options: DestinationOption[]; id?: string; emptyLabel?: string; class?: string }>();

/** "<type>:<value>"; external numbers are typed in, everything else is picked. */
const model = defineModel<string | null>({ default: null });

const groups = computed(() => {
    const map = new Map<string, DestinationOption[]>();
    for (const option of props.options) {
        map.set(option.group, [...(map.get(option.group) ?? []), option]);
    }
    return [...map.entries()];
});

const isExternal = computed(() => model.value?.startsWith('external:') ?? false);
const external = ref(isExternal.value ? model.value!.slice(9) : '');
const selected = computed({
    get: () => (isExternal.value ? 'external:' : (model.value ?? '')),
    set: (value: string) => {
        if (value === 'external:') {
            model.value = `external:${external.value}`;
        } else {
            model.value = value || null;
        }
    },
});

watch(external, (digits) => {
    if (isExternal.value) model.value = `external:${digits.replace(/[^\d+]/g, '')}`;
});
</script>

<template>
    <div :class="cn('grid gap-2', props.class)">
        <select
            :id="id"
            v-model="selected"
            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring dark:bg-background"
        >
            <option value="">{{ emptyLabel ?? '— None —' }}</option>
            <optgroup v-for="[group, items] in groups" :key="group" :label="group">
                <option v-for="option in items" :key="option.value" :value="option.value">{{ option.label }}</option>
            </optgroup>
            <optgroup label="Outside">
                <option value="external:">External number…</option>
            </optgroup>
        </select>
        <Input v-if="isExternal" v-model="external" inputmode="tel" placeholder="e.g. 0771234567" aria-label="External number" />
    </div>
</template>
