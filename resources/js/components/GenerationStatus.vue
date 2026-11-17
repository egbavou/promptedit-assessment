<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { Generation } from '@/types/generation';

defineProps<{ generation: Generation }>();
</script>

<template>
    <Badge
        aria-live="polite"
        :variant="
            generation.status === 'failed'
                ? 'destructive'
                : generation.status === 'succeeded'
                  ? 'default'
                  : 'secondary'
        "
    >
        {{ generation.status_label }}
    </Badge>
</template>
