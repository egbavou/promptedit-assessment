<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import BuyCreditsButton from '@/components/BuyCreditsButton.vue';

const page = usePage();
</script>

<template>
    <div
        v-if="page.props.balance"
        class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-4 py-3 sm:px-6"
    >
        <p aria-live="polite" class="text-sm tabular-nums">
            Available <strong>{{ page.props.balance.available }}</strong>
            <span class="text-muted-foreground">
                · Reserved {{ page.props.balance.reserved }}</span
            >
        </p>
        <BuyCreditsButton />
        <p
            v-if="page.props.flash.success"
            role="status"
            class="text-sm text-muted-foreground"
        >
            {{ page.props.flash.success }}
        </p>
    </div>
</template>
