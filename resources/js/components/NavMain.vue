<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const { label = 'Platform' } = defineProps<{
    items: NavItem[];
    label?: string;
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="!item.external && isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <a
                        v-if="item.external"
                        :href="toUrl(item.href)"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <ExternalLink
                            class="ml-auto size-3.5 text-muted-foreground group-data-[collapsible=icon]:hidden"
                            aria-label="Opens in a new tab"
                        />
                    </a>
                    <Link v-else :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <span
                            v-if="item.badge"
                            class="ml-auto text-xs text-muted-foreground group-data-[collapsible=icon]:hidden"
                            >{{ item.badge }}</span
                        >
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
