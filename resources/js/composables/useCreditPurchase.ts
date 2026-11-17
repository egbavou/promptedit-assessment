import { router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { purchase } from '@/routes/credits';

const processing = ref(false);
const error = ref('');
const completedPurchases = ref(0);
let idempotencyKey: string | null = null;
let pageUrl: string | null = null;

export function useCreditPurchase() {
    const page = usePage();
    watch(
        () => page.url,
        (url) => {
            if (url !== pageUrl && !processing.value) {
                pageUrl = url;
                idempotencyKey = crypto.randomUUID();
                error.value = '';
            }
        },
        { immediate: true },
    );

    function buy() {
        if (processing.value) return;
        idempotencyKey ??= crypto.randomUUID();
        processing.value = true;
        error.value = '';
        router.post(
            purchase(),
            { idempotency_key: idempotencyKey },
            {
                preserveScroll: true,
                onSuccess: () => {
                    idempotencyKey = crypto.randomUUID();
                    completedPurchases.value++;
                },
                onError: (errors) => {
                    error.value =
                        errors.idempotency_key ??
                        'Credits could not be added. Please try again.';
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    }

    return { processing, error, buy, completedPurchases };
}
