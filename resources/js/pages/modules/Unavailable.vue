<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { resolveIcon } from '@/lib/icons';
import { Head } from '@inertiajs/vue3';
import { Construction } from 'lucide-vue-next';

const props = defineProps<{
    module: { title: string; icon: string; phase: number | null };
}>();

const breadcrumbs = [{ title: props.module.title, href: '#' }];
</script>

<template>
    <Head :title="module.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 items-center justify-center p-6">
            <div class="max-w-md text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                    <component :is="resolveIcon(module.icon)" class="size-7" />
                </div>
                <h1 class="mt-5 text-xl font-semibold tracking-tight">{{ module.title }}</h1>
                <p
                    class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-700 dark:text-amber-400"
                >
                    <Construction class="size-3.5" />
                    Not yet available
                </p>
                <p class="mt-4 text-sm text-muted-foreground">
                    This module is part of the platform roadmap<template v-if="module.phase">
                        and is scheduled for delivery in Phase {{ module.phase }}</template
                    >. Nothing on this page is functional yet, and no data has been created for it.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
