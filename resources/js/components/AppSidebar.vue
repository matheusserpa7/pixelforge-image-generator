<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, ChartColumn, Image, Layers, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { report } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import { index as generations } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Generations',
        href: generations(),
        icon: Image,
    },
    {
        title: 'Credits',
        href: credits(),
        icon: Wallet,
        badge: page.props.credits?.available.display,
    },
]);

const adminNavItems = computed<NavItem[]>(() => {
    const dashboards = page.props.auth.dashboards;

    if (!page.props.auth.isAdmin || dashboards === null) {
        return [];
    }

    return [
        { title: 'Report', href: report(), icon: ChartColumn },
        {
            title: 'Queues (Horizon)',
            href: dashboards.horizon,
            icon: Layers,
            external: true,
        },
        {
            title: 'Metrics (Pulse)',
            href: dashboards.pulse,
            icon: Activity,
            external: true,
        },
    ];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="generations()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="adminNavItems.length > 0"
                label="Admin"
                :items="adminNavItems"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
