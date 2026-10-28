<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { resolveIcon } from '@/lib/icons';
import { type ModuleNavGroup, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Search } from 'lucide-vue-next';

const props = defineProps<{
    groups: ModuleNavGroup[];
}>();

const page = usePage<SharedData>();
const search = ref('');
const filteredGroups = computed(() => props.groups.map(group => ({
    ...group,
    items: group.items.filter(item => item.title.toLowerCase().includes(search.value.trim().toLowerCase())),
})).filter(group => group.items.length));

const matches = (href: string) => page.url === href || page.url.startsWith(href + '/') || page.url.startsWith(href + '?');

// Highlight only the most specific matching item, so "/platform" doesn't light up on "/platform/pbx-servers".
const activeHref = computed(
    () =>
        props.groups
            .flatMap((g) => g.items.map((i) => i.href))
            .filter(matches)
            .sort((a, b) => b.length - a.length)[0],
);

const isActive = (href: string) => href === activeHref.value;
</script>

<template>
    <div class="px-3 pt-4 group-data-[collapsible=icon]:hidden">
        <label class="flex items-center gap-2 rounded-xl border border-sidebar-border bg-white/5 px-3 text-sidebar-foreground focus-within:ring-2 focus-within:ring-sidebar-ring">
            <Search class="size-4 shrink-0" />
            <input v-model="search" type="search" aria-label="Find a page" placeholder="Find a page…" class="h-10 w-full min-w-0 border-0 bg-transparent p-0 text-sm placeholder:text-sidebar-foreground/60 focus:ring-0" />
        </label>
        <p v-if="!filteredGroups.length" class="py-4 text-xs text-sidebar-foreground">No matching pages. Try another name.</p>
    </div>
    <SidebarGroup v-for="group in filteredGroups" :key="group.group" class="px-3 py-0">
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
