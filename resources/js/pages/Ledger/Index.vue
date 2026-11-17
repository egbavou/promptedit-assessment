<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageNavigation from '@/components/PageNavigation.vue';
import { formatUtcDate } from '@/lib/date';
import { index } from '@/routes/ledger';
import type { Paginated } from '@/types/generation';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Ledger', href: index() }] },
});
defineProps<{
    entries: Paginated<{
        id: number;
        type_label: string;
        amount: string;
        created_at: string;
        show_url: string | null;
        prompt: string | null;
    }>;
}>();
</script>

<template>
    <Head title="Ledger" />
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold">Credit ledger</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Every purchase, reservation, release, and charge. All amounts
                are in USD.
            </p>
        </div>
        <p
            v-if="!entries.data.length"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No entries yet. Buy credits to create your first image.
        </p>
        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Your credit movements, newest first
                </caption>
                <thead class="border-b bg-muted/50 text-muted-foreground">
                    <tr>
                        <th scope="col" class="p-4">Date</th>
                        <th scope="col" class="p-4">Type</th>
                        <th scope="col" class="p-4 text-right">Amount</th>
                        <th scope="col" class="p-4">Generation</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in entries.data"
                        :key="entry.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-4 whitespace-nowrap">
                            {{ formatUtcDate(entry.created_at) }}
                        </td>
                        <th
                            scope="row"
                            class="p-4 font-medium whitespace-nowrap"
                        >
                            {{ entry.type_label }}
                        </th>
                        <td
                            class="p-4 text-right font-medium whitespace-nowrap tabular-nums"
                        >
                            {{ entry.amount }}
                        </td>
                        <td class="min-w-48 p-4">
                            <Link
                                v-if="entry.show_url"
                                :href="entry.show_url"
                                class="line-clamp-2 underline underline-offset-4"
                                >{{ entry.prompt }}</Link
                            ><span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PageNavigation
            v-if="entries.data.length"
            :links="entries.links"
            :page="entries.meta.current_page"
        />
    </div>
</template>
