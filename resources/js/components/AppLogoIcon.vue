<script setup lang="ts">
import { AudioLines } from 'lucide-vue-next';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: string;
}

defineProps<Props>();
</script>

<template>
    <!-- Stroke-based mark; callers' fill-* classes must not fill it in. -->
    <AudioLines v-bind="$attrs" :class="className" style="fill: none" />
</template>
