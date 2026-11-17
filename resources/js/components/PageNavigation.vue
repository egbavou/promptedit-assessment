<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

defineProps<{
    links: { prev: string | null; next: string | null };
    page: number;
}>();
</script>

<template>
    <nav
        aria-label="Pagination"
        class="flex items-center justify-between gap-3 pt-4"
    >
        <Button v-if="links.prev" variant="outline" as-child
            ><Link :href="links.prev" preserve-scroll>Previous</Link></Button
        >
        <span v-else />
        <span class="text-sm text-muted-foreground">Page {{ page }}</span>
        <Button v-if="links.next" variant="outline" as-child
            ><Link :href="links.next" preserve-scroll>Next</Link></Button
        >
        <span v-else />
    </nav>
</template>
