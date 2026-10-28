<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { resolveIcon } from '@/lib/icons';
import { type ModuleNavGroup, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    groups: ModuleNavGroup[];
}>();

const page = usePage<SharedData>();

const matches = (href: string) => page.url === href || page.url.startsWith(href + '/') || page.url.startsWith(href + '?');

// Highlight only the most specific matching item, so "/platform" doesn't light up on "/platform/pbx-servers".
const activeHref = computed(() =>
    props.groups
        .flatMap((g) => g.items.map((i) => i.href))
        .filter(matches)
        .sort((a, b) => b.length - a.length)[0],
);

const isActive = (href: string) => href === activeHref.value;
</script>

<template>
    <SidebarGroup v-for="group in groups" :key="group.group" class="px-2 py-0">
        <SidebarGroupLabel>{{ group.group }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.href">
                <SidebarMenuButton as-child :is-active="isActive(item.href)" :tooltip="item.title">
                    <Link :href="item.href" :class="{ 'opacity-60': !item.available }">
                        <component :is="resolveIcon(item.icon)" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge v-if="!item.available" class="text-[10px] font-normal text-muted-foreground">Soon</SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
