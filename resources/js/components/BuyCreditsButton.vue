<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useCreditPurchase } from '@/composables/useCreditPurchase';

const { processing, error, buy } = useCreditPurchase();
</script>

<template>
    <div class="flex flex-col items-start gap-1">
        <Button
            type="button"
            variant="outline"
            :disabled="processing"
            @click="buy"
        >
            {{ processing ? 'Adding credits…' : 'Buy $5' }}
        </Button>
        <p v-if="error" role="alert" class="max-w-64 text-sm text-destructive">
            {{ error }}
        </p>
    </div>
</template>
