<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ChartNoAxesCombined, Images, ReceiptText } from '@lucide/vue';
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
import { index as generationsIndex } from '@/routes/generations';
import { index as ledgerIndex } from '@/routes/ledger';
import type { NavItem } from '@/types';

const page = usePage();
const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Generate', href: generationsIndex(), icon: Images },
    { title: 'Ledger', href: ledgerIndex(), icon: ReceiptText },
    ...(page.props.auth.user.is_admin
        ? [{ title: 'Report', href: report(), icon: ChartNoAxesCombined }]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="generationsIndex()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
