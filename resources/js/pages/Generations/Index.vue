<script setup lang="ts">
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import BuyCreditsButton from '@/components/BuyCreditsButton.vue';
import GenerationStatus from '@/components/GenerationStatus.vue';
import InputError from '@/components/InputError.vue';
import PageNavigation from '@/components/PageNavigation.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCreditPurchase } from '@/composables/useCreditPurchase';
import { formatUtcDate } from '@/lib/date';
import { estimate as estimateRoute, index, store } from '@/routes/generations';
import type { Generation, Paginated } from '@/types/generation';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Generate', href: index() }] },
});
const props = defineProps<{
    generations: Paginated<Generation>;
    options: {
        sizes: string[];
        qualities: string[];
        max_prompt_length: number;
        max_reference_images: number;
    };
}>();
const page = usePage();
const referencePlaceholder = 'https://example.com/image.png';
const form = useForm({
    prompt: '',
    size: props.options.sizes[0],
    quality: props.options.qualities[1],
    reference_images: [] as string[],
});
const promptLength = computed(() => [...form.prompt].length);
const estimateInput = computed(() =>
    JSON.stringify({
        prompt: form.prompt,
        size: form.size,
        quality: form.quality,
        reference_images: form.reference_images,
    }),
);
const estimate = ref<{
    estimate: string;
    max_nanodollars: number;
    input: string;
} | null>(null);
const canAfford = computed(
    () =>
        !estimate.value ||
        !page.props.balance ||
        page.props.balance.available_nanodollars >=
            estimate.value.max_nanodollars,
);
const insufficientCredits = computed(
    () => estimate.value?.input === estimateInput.value && !canAfford.value,
);
const estimating = ref(true);
const estimateError = ref('');
const estimateErrors = ref<Record<string, string>>({});
const errors = computed<Record<string, string>>(() => ({
    ...estimateErrors.value,
    ...form.errors,
}));

watch(
    estimateInput,
    (input, _, onCleanup) => {
        const controller = new AbortController();
        let active = true;
        estimating.value = true;
        estimateError.value = '';
        estimateErrors.value = {};
        const timer = setTimeout(async () => {
            try {
                const xsrf = document.cookie
                    .split('; ')
                    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                    ?.slice('XSRF-TOKEN='.length);
                const response = await fetch(estimateRoute.url(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(xsrf ?? ''),
                    },
                    body: input,
                });
                const result = await response.json();
                if (!active) return;
                if (!response.ok) {
                    estimateError.value =
                        response.status === 422
                            ? 'Check the fields below to get an estimate.'
                            : (result.message ??
                              'Could not estimate the cost. Please try again.');
                    for (const [key, messages] of Object.entries(
                        result.errors ?? {},
                    )) {
                        estimateErrors.value[key] = (messages as string[])[0];
                    }
                    return;
                }
                estimate.value = { ...result, input };
            } catch {
                if (active)
                    estimateError.value =
                        'Could not estimate the cost. Check your connection and try again.';
            } finally {
                if (active) estimating.value = false;
            }
        }, 300);
        onCleanup(() => {
            active = false;
            clearTimeout(timer);
            controller.abort();
        });
    },
    { immediate: true },
);

function clearErrorsFor(fields: string[]) {
    for (const key of Object.keys(form.errors)) {
        if (
            fields.some((field) => key === field || key.startsWith(`${field}.`))
        ) {
            form.clearErrors(key as keyof typeof form.errors);
        }
    }
    for (const key of Object.keys(estimateErrors.value)) {
        if (
            fields.some((field) => key === field || key.startsWith(`${field}.`))
        ) {
            delete estimateErrors.value[key];
        }
    }
}
function fieldEdited(field: keyof ReturnType<typeof form.data>) {
    clearErrorsFor([field, 'balance', 'pricing']);
}
const { completedPurchases } = useCreditPurchase();
watch(completedPurchases, () => clearErrorsFor(['balance', 'pricing']));

const { start, stop } = usePoll(
    2000,
    { only: ['generations', 'balance'] },
    { autoStart: false },
);
watch(
    () => props.generations.data.some((generation) => !generation.is_final),
    (pending) => {
        if (pending) start();
        else stop();
    },
    { immediate: true },
);
onUnmounted(stop);

const canGenerate = computed(
    () =>
        !form.processing &&
        Boolean(form.prompt.trim()) &&
        !insufficientCredits.value,
);
function addReference() {
    form.reference_images.push('');
    fieldEdited('reference_images');
}
function removeReference(index: number) {
    form.reference_images.splice(index, 1);
    fieldEdited('reference_images');
}
function updateReference(index: number, value: string | number) {
    form.reference_images[index] = String(value);
    fieldEdited('reference_images');
}

function submit() {
    if (!canGenerate.value) return;
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}
</script>

<template>
    <Head title="Generate" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Create something worth a closer look.
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Describe an image. See the estimate, then let Pixelforge bring
                it to life.
            </p>
        </div>
        <Card>
            <CardHeader><CardTitle>New generation</CardTitle></CardHeader>
            <CardContent>
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="prompt">Prompt</Label>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            @input="fieldEdited('prompt')"
                            rows="4"
                            required
                            aria-describedby="prompt-counter prompt-error"
                            :aria-invalid="Boolean(errors.prompt)"
                            class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                        />
                        <div
                            id="prompt-counter"
                            class="text-right text-xs text-muted-foreground tabular-nums"
                        >
                            {{ promptLength }} / {{ options.max_prompt_length }}
                        </div>
                        <InputError
                            id="prompt-error"
                            :message="errors.prompt"
                        />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="size">Size</Label>
                            <Select
                                v-model="form.size"
                                @update:model-value="fieldEdited('size')"
                                ><SelectTrigger
                                    id="size"
                                    class="w-full"
                                    aria-describedby="size-error"
                                    ><SelectValue /></SelectTrigger
                                ><SelectContent
                                    ><SelectItem
                                        v-for="size in options.sizes"
                                        :key="size"
                                        :value="size"
                                        >{{ size }}</SelectItem
                                    ></SelectContent
                                ></Select
                            >
                            <InputError
                                id="size-error"
                                :message="errors.size"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="quality">Quality</Label>
                            <Select
                                v-model="form.quality"
                                @update:model-value="fieldEdited('quality')"
                                ><SelectTrigger
                                    id="quality"
                                    class="w-full"
                                    aria-describedby="quality-error"
                                    ><SelectValue /></SelectTrigger
                                ><SelectContent
                                    ><SelectItem
                                        v-for="quality in options.qualities"
                                        :key="quality"
                                        :value="quality"
                                        class="capitalize"
                                        >{{ quality }}</SelectItem
                                    ></SelectContent
                                ></Select
                            >
                            <InputError
                                id="quality-error"
                                :message="errors.quality"
                            />
                        </div>
                    </div>
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-medium">
                            Reference images
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            >
                        </legend>
                        <div
                            v-for="(
                                reference, referenceIndex
                            ) in form.reference_images"
                            :key="referenceIndex"
                            class="space-y-2"
                        >
                            <Label :for="`reference-${referenceIndex}`"
                                >Reference image
                                {{ referenceIndex + 1 }} URL</Label
                            >
                            <div class="flex gap-2">
                                <Input
                                    :id="`reference-${referenceIndex}`"
                                    :model-value="reference"
                                    @update:model-value="
                                        updateReference(referenceIndex, $event)
                                    "
                                    type="url"
                                    required
                                    :placeholder="referencePlaceholder"
                                    :aria-describedby="`reference-error-${referenceIndex}`"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    :aria-label="`Remove reference image ${referenceIndex + 1}`"
                                    @click="removeReference(referenceIndex)"
                                    >Remove</Button
                                >
                            </div>
                            <InputError
                                :id="`reference-error-${referenceIndex}`"
                                :message="
                                    errors[`reference_images.${referenceIndex}`]
                                "
                            />
                        </div>
                        <InputError :message="errors.reference_images" />
                        <Button
                            v-if="
                                form.reference_images.length <
                                options.max_reference_images
                            "
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addReference"
                            >Add reference image</Button
                        >
                    </fieldset>
                    <div class="space-y-3 border-t pt-5">
                        <p
                            aria-live="polite"
                            class="text-sm font-medium tabular-nums"
                        >
                            {{
                                estimate
                                    ? `Estimated cost: ${estimate.estimate}`
                                    : estimating
                                      ? 'Estimating…'
                                      : ''
                            }}
                            <span
                                v-if="estimate && estimating"
                                class="ml-2 text-xs font-normal text-muted-foreground"
                                >Updating…</span
                            >
                        </p>
                        <InputError :message="estimateError" />
                        <p class="text-xs text-muted-foreground">
                            The maximum estimate is reserved until completion.
                            Failed generations are free.
                        </p>
                        <InputError :message="errors.balance" />
                        <InputError :message="errors.pricing" />
                        <div class="flex flex-wrap items-center gap-3">
                            <Button type="submit" :disabled="!canGenerate">{{
                                form.processing ? 'Submitting…' : 'Generate'
                            }}</Button>
                            <template v-if="insufficientCredits">
                                <span class="text-sm text-destructive"
                                    >Not enough credits for this
                                    generation</span
                                >
                                <BuyCreditsButton />
                            </template>
                        </div>
                    </div>
                </form>
            </CardContent>
        </Card>
        <section aria-labelledby="history-title">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 id="history-title" class="text-xl font-semibold">
                    Your generations
                </h2>
                <span class="text-xs text-muted-foreground">Newest first</span>
            </div>
            <div
                v-if="!generations.data.length"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Your first image starts with a prompt. Generations will appear
                here.
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <Card
                    v-for="generation in generations.data"
                    :key="generation.id"
                    class="overflow-hidden py-0"
                >
                    <Link
                        :href="generation.show_url"
                        class="block focus-visible:outline-2 focus-visible:outline-ring"
                        :aria-label="`View generation: ${generation.prompt}`"
                    >
                        <img
                            v-if="generation.image_url"
                            :src="generation.image_url"
                            :alt="generation.prompt"
                            loading="lazy"
                            class="aspect-video w-full object-cover"
                        />
                        <div
                            v-else
                            class="flex aspect-video items-center justify-center bg-muted px-4 text-center text-sm text-muted-foreground"
                        >
                            {{
                                generation.image_expired
                                    ? 'Image expired'
                                    : generation.status === 'succeeded'
                                      ? 'Image is being saved'
                                      : generation.status_label
                            }}
                        </div>
                    </Link>
                    <CardContent class="space-y-3 pb-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <GenerationStatus :generation="generation" /><span
                                class="text-xs text-muted-foreground"
                                >{{ generation.size }} ·
                                {{ generation.quality }}</span
                            >
                        </div>
                        <Link
                            :href="generation.show_url"
                            class="line-clamp-2 text-sm font-medium hover:underline"
                            >{{ generation.prompt }}</Link
                        >
                        <p class="text-xs text-muted-foreground">
                            {{ formatUtcDate(generation.created_at) }}
                        </p>
                        <p class="text-sm tabular-nums">
                            {{
                                generation.price
                                    ? `Charged ${generation.price}`
                                    : `Estimated ${generation.estimate}`
                            }}
                        </p>
                        <p
                            v-if="generation.error_message"
                            class="text-sm text-destructive"
                        >
                            {{ generation.error_message }}
                        </p>
                        <Link
                            :href="generation.show_url"
                            class="text-xs font-medium underline underline-offset-4"
                            >View details</Link
                        >
                    </CardContent>
                </Card>
            </div>
            <PageNavigation
                v-if="generations.data.length"
                :links="generations.links"
                :page="generations.meta.current_page"
            />
        </section>
    </div>
</template>
