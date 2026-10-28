<script setup lang="ts">
import { ArrowLeftRight, PhoneIncoming, PhoneMissed, PhoneOutgoing } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ direction: string; status?: string }>();

const icon = computed(() => {
    if (props.status === 'missed') return PhoneMissed;
    return { inbound: PhoneIncoming, outbound: PhoneOutgoing }[props.direction] ?? ArrowLeftRight;
});
const tone = computed(() =>
    props.status === 'missed'
        ? 'text-red-600'
        : props.direction === 'inbound'
          ? 'text-sky-600'
          : props.direction === 'outbound'
            ? 'text-violet-600'
            : 'text-muted-foreground',
);
</script>

<template>
    <component :is="icon" class="size-4 shrink-0" :class="tone" :aria-label="`${direction} call`" />
</template>
