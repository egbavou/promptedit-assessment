<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';
import GenerationStatus from '@/components/GenerationStatus.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatUtcDate } from '@/lib/date';
import { index } from '@/routes/generations';
import type { Breakdown, Generation } from '@/types/generation';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Generate', href: index() },
            { title: 'Generation' },
        ],
    },
});
const props = defineProps<{
    generation: Generation;
    breakdown: Breakdown | null;
}>();
const { start, stop } = usePoll(
    2000,
    { only: ['generation', 'breakdown', 'balance'] },
    { autoStart: false },
);
watch(
    () => props.generation.is_final,
    (final) => {
        if (final) stop();
        else start();
    },
    { immediate: true },
);
</script>

<template>
    <Head title="Generation details" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <Link
            :href="index()"
            class="text-sm text-muted-foreground hover:underline"
            >← All generations</Link
        >
        <div class="space-y-3">
            <GenerationStatus :generation="generation" />
            <h1 class="text-xl font-semibold break-words">
                {{ generation.prompt }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ generation.size }} · {{ generation.quality }}
            </p>
        </div>
        <img
            v-if="generation.image_url"
            :src="generation.image_url"
            :alt="generation.prompt"
            class="max-h-[70vh] w-full rounded-xl border bg-muted object-contain"
        />
        <div
            v-else
            class="flex min-h-48 items-center justify-center rounded-xl border bg-muted p-6 text-muted-foreground"
        >
            {{
                generation.image_expired
                    ? 'Image expired'
                    : generation.status === 'succeeded'
                      ? 'Image is being saved'
                      : generation.status_label
            }}
        </div>
        <p
            v-if="generation.error_message"
            role="status"
            class="text-sm text-destructive"
        >
            {{ generation.error_message }}
        </p>
        <Card v-if="breakdown">
            <CardHeader><CardTitle>Price breakdown</CardTitle></CardHeader>
            <CardContent class="space-y-4">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm tabular-nums">
                        <caption class="sr-only">
                            Token usage multiplied by stored rates, with exact
                            costs in USD
                        </caption>
                        <thead class="border-b text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="py-3 pr-4">
                                    Token type
                                </th>
                                <th scope="col" class="px-3 text-right">
                                    Tokens
                                </th>
                                <th scope="col" class="px-3">Rate</th>
                                <th scope="col" class="pl-3 text-right">
                                    Cost
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in breakdown.lines"
                                :key="line.label"
                                class="border-b"
                            >
                                <th scope="row" class="py-3 pr-4 font-normal">
                                    {{ line.label }}
                                </th>
                                <td class="px-3 text-right">
                                    {{ line.tokens }}
                                </td>
                                <td class="px-3 whitespace-nowrap">
                                    {{ line.rate }}
                                </td>
                                <td class="pl-3 text-right whitespace-nowrap">
                                    {{ line.cost }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <dl class="space-y-3 text-sm tabular-nums">
                    <div class="flex flex-wrap justify-between gap-2">
                        <dt>Provider cost</dt>
                        <dd>{{ breakdown.provider_cost }}</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2">
                        <dt>× 1.25 (25% markup)</dt>
                        <dd>{{ breakdown.marked_up }}</dd>
                    </div>
                    <div
                        class="flex flex-wrap justify-between gap-2 border-t pt-3 font-semibold"
                    >
                        <dt>Charged</dt>
                        <dd>{{ breakdown.price }}</dd>
                    </div>
                </dl>
                <p class="text-xs text-muted-foreground">
                    Rounded up to the next $0.0001.
                </p>
            </CardContent>
        </Card>
        <p v-else class="text-sm tabular-nums">
            Estimated cost: {{ generation.estimate }}
        </p>
        <Card
            ><CardHeader><CardTitle>Generation details</CardTitle></CardHeader
            ><CardContent>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Pixelforge ID</dt>
                        <dd class="mt-1 break-all">
                            {{
                                generation.pixelforge_id ??
                                'Awaiting submission'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Created</dt>
                        <dd class="mt-1">
                            {{ formatUtcDate(generation.created_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Started</dt>
                        <dd class="mt-1">
                            {{
                                generation.started_at
                                    ? formatUtcDate(generation.started_at)
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Completed</dt>
                        <dd class="mt-1">
                            {{
                                generation.completed_at
                                    ? formatUtcDate(generation.completed_at)
                                    : '—'
                            }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="generation.reference_images.length"
                    class="mt-5 space-y-2"
                >
                    <h3 class="text-sm font-medium">Reference images</h3>
                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="(url, i) in generation.reference_images"
                            :key="i"
                        >
                            <a
                                :href="url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="break-all underline underline-offset-4"
                                >{{ url }}</a
                            >
                        </li>
                    </ul>
                </div>
            </CardContent></Card
        >
    </div>
</template>
