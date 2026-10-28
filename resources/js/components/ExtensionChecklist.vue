<script setup lang="ts">
defineProps<{ extensions: { id: number; number: string; name: string }[] }>();

const model = defineModel<number[]>({ required: true });
</script>

<template>
    <div>
        <p v-if="!extensions.length" class="text-sm text-muted-foreground">Create extensions first.</p>
        <div v-else class="max-h-56 space-y-1 overflow-y-auto rounded-md border p-2">
            <label
                v-for="ext in extensions"
                :key="ext.id"
                class="flex cursor-pointer items-center gap-3 rounded px-2 py-1.5 text-sm hover:bg-muted/50"
            >
                <input v-model="model" type="checkbox" :value="ext.id" class="size-4 rounded border-input" />
                <span class="font-mono font-semibold">{{ ext.number }}</span>
                <span class="flex-1">{{ ext.name }}</span>
                <span v-if="model.includes(ext.id)" class="text-xs text-muted-foreground">#{{ model.indexOf(ext.id) + 1 }}</span>
            </label>
        </div>
    </div>
</template>
