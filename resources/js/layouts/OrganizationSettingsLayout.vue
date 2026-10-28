<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { Activity, Bell, Building2, Clock, Lock, PhoneCall } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ section: string; title: string; description: string; canManage?: boolean }>();

const sections = [
    { key: 'general', title: 'General', icon: Building2 },
    { key: 'business-hours', title: 'Business hours', icon: Clock },
    { key: 'calls', title: 'Call settings', icon: PhoneCall },
    { key: 'notifications', title: 'Notifications', icon: Bell },
    { key: 'security', title: 'Security', icon: Lock },
    { key: 'diagnostics', title: 'Diagnostics', icon: Activity },
];

const breadcrumbs = computed(() => [
    { title: 'Settings', href: '/organization-settings' },
    { title: props.title, href: `/organization-settings/${props.section}` },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Organization settings</h1>
                <p class="text-sm text-muted-foreground">Configuration shared by everyone in your organization</p>
            </div>

            <div class="flex flex-col gap-6 lg:flex-row lg:gap-10">
                <nav
                    class="-mx-1 flex gap-1 overflow-x-auto pb-1 lg:mx-0 lg:w-52 lg:shrink-0 lg:flex-col lg:overflow-visible"
                    aria-label="Settings sections"
                >
                    <Link
                        v-for="s in sections"
                        :key="s.key"
                        :href="route('organization-settings.show', { section: s.key })"
                        class="flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors"
                        :class="
                            s.key === section
                                ? 'bg-primary/10 font-medium text-primary'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        "
                    >
                        <component :is="s.icon" class="size-4" />
                        {{ s.title }}
                    </Link>
                </nav>

                <div class="min-w-0 max-w-3xl flex-1">
                    <div class="mb-6">
                        <h2 class="text-lg font-semibold tracking-tight">{{ title }}</h2>
                        <p class="text-sm text-muted-foreground">{{ description }}</p>
                        <p v-if="canManage === false" class="mt-3 rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                            You can view these settings but not change them.
                        </p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
