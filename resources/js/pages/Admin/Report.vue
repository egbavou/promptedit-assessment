<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report as reportRoute } from '@/routes/admin';

type Amounts = {
    generations: number;
    billed_nanodollars: number;
    cost_nanodollars: number;
    margin_nanodollars: number;
    billed: string;
    cost: string;
    margin: string;
};
type Comparison = {
    generations: number;
    cost_nanodollars: number;
    cost: string;
    match: boolean;
    difference_nanodollars: number;
    difference: string;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Report', href: reportRoute() }] },
});
const props = defineProps<{
    filters: { start: string; end: string };
    report: {
        days: (Amounts & { date: string; pixelforge: Comparison | null })[];
        users: (Amounts & { id: number; name: string; email: string })[];
        totals: Amounts;
        pixelforge: { matching_days: number; total_days: number } | null;
        message: string | null;
    };
}>();
const form = useForm({ ...props.filters });
watch(
    () => props.filters,
    (filters) => {
        form.start = filters.start;
        form.end = filters.end;
    },
);
function submit() {
    form.get(reportRoute.url(), { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Report" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold">Profit report</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                User billing, recorded provider costs, and reconciliation with
                Pixelforge. All amounts are in USD.
            </p>
        </div>
        <form class="flex flex-wrap items-start gap-4" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="report-start">Start date (UTC)</Label>
                <Input
                    id="report-start"
                    v-model="form.start"
                    type="date"
                    required
                    :aria-invalid="Boolean(form.errors.start)"
                    aria-describedby="report-start-error"
                />
                <InputError
                    id="report-start-error"
                    :message="form.errors.start"
                />
            </div>
            <div class="space-y-2">
                <Label for="report-end">End date (UTC)</Label>
                <Input
                    id="report-end"
                    v-model="form.end"
                    type="date"
                    required
                    :aria-invalid="Boolean(form.errors.end)"
                    aria-describedby="report-end-error"
                />
                <InputError id="report-end-error" :message="form.errors.end" />
            </div>
            <Button class="mt-5" type="submit" :disabled="form.processing">
                {{ form.processing ? 'Loading…' : 'Update report' }}
            </Button>
            <p class="w-full text-xs text-muted-foreground">
                Up to 92 days, inclusive. Pixelforge usage is cached for 5
                minutes.
            </p>
        </form>
        <Alert v-if="report.message" role="status">
            <AlertDescription>{{ report.message }}</AlertDescription>
        </Alert>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardTitle>Total billed</CardTitle>
                </CardHeader>
                <CardContent class="text-xl font-semibold tabular-nums">
                    {{ report.totals.billed }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Recorded Pixelforge cost</CardTitle>
                </CardHeader>
                <CardContent class="text-xl font-semibold tabular-nums">
                    {{ report.totals.cost }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Margin</CardTitle>
                </CardHeader>
                <CardContent class="text-xl font-semibold tabular-nums">
                    {{ report.totals.margin }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Days matching Pixelforge</CardTitle>
                </CardHeader>
                <CardContent class="text-xl font-semibold tabular-nums">
                    {{
                        report.pixelforge
                            ? `${report.pixelforge.matching_days} / ${report.pixelforge.total_days}`
                            : 'Unavailable'
                    }}
                </CardContent>
            </Card>
        </div>
        <p class="text-sm text-muted-foreground">
            Days are UTC calendar days of completion, the same way Pixelforge
            bills.
        </p>
        <section aria-labelledby="daily-title" class="space-y-3">
            <h2 id="daily-title" class="text-xl font-semibold">By day</h2>
            <div
                class="overflow-x-auto rounded-xl border"
                role="region"
                aria-label="Daily report"
                tabindex="0"
            >
                <table
                    class="w-full text-right text-sm whitespace-nowrap tabular-nums"
                >
                    <caption class="sr-only">
                        Daily billing and exact Pixelforge reconciliation;
                        differences are our cost minus Pixelforge cost
                    </caption>
                    <thead class="border-b bg-muted/50 text-muted-foreground">
                        <tr>
                            <th scope="col" class="p-4 text-left">UTC date</th>
                            <th scope="col" class="p-4">Our generations</th>
                            <th scope="col" class="p-4">Billed</th>
                            <th scope="col" class="p-4">Our cost</th>
                            <th scope="col" class="p-4">Margin</th>
                            <th scope="col" class="p-4">
                                Pixelforge generations
                            </th>
                            <th scope="col" class="p-4">Pixelforge cost</th>
                            <th scope="col" class="p-4">Reconciliation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in report.days"
                            :key="day.date"
                            class="border-b last:border-0"
                        >
                            <th scope="row" class="p-4 text-left font-medium">
                                {{ day.date }}
                            </th>
                            <td class="p-4">{{ day.generations }}</td>
                            <td class="p-4">{{ day.billed }}</td>
                            <td class="p-4">{{ day.cost }}</td>
                            <td class="p-4">{{ day.margin }}</td>
                            <td class="p-4">
                                {{ day.pixelforge?.generations ?? '—' }}
                            </td>
                            <td class="p-4">
                                {{ day.pixelforge?.cost ?? '—' }}
                            </td>
                            <td class="p-4">
                                <span
                                    v-if="day.pixelforge"
                                    :class="
                                        day.pixelforge.match
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : 'text-destructive'
                                    "
                                >
                                    <span aria-hidden="true">{{
                                        day.pixelforge.match ? '✓' : '✗'
                                    }}</span>
                                    <span class="sr-only">{{
                                        day.pixelforge.match
                                            ? 'Matches Pixelforge'
                                            : `Differs from Pixelforge by ${day.pixelforge.difference}`
                                    }}</span>
                                    <span
                                        v-if="
                                            day.generations !==
                                            day.pixelforge.generations
                                        "
                                        class="sr-only"
                                    >
                                        Generation count differs: ours
                                        {{ day.generations }}, Pixelforge
                                        {{ day.pixelforge.generations }}.
                                    </span>
                                    <span
                                        v-if="!day.pixelforge.match"
                                        aria-hidden="true"
                                        class="ml-2"
                                        >{{ day.pixelforge.difference
                                        }}<template
                                            v-if="
                                                day.generations !==
                                                day.pixelforge.generations
                                            "
                                        >
                                            · Count differs</template
                                        ></span
                                    >
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >Unavailable</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section aria-labelledby="users-title" class="space-y-3">
            <h2 id="users-title" class="text-xl font-semibold">By user</h2>
            <p
                v-if="!report.users.length"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No successful generations in this date range.
            </p>
            <div
                v-else
                class="overflow-x-auto rounded-xl border"
                role="region"
                aria-label="Per-user report"
                tabindex="0"
            >
                <table
                    class="w-full text-right text-sm whitespace-nowrap tabular-nums"
                >
                    <caption class="sr-only">
                        Billing, recorded provider costs, and margins per user
                    </caption>
                    <thead class="border-b bg-muted/50 text-muted-foreground">
                        <tr>
                            <th scope="col" class="p-4 text-left">User</th>
                            <th scope="col" class="p-4 text-left">Email</th>
                            <th scope="col" class="p-4">Generations</th>
                            <th scope="col" class="p-4">Billed</th>
                            <th scope="col" class="p-4">Cost</th>
                            <th scope="col" class="p-4">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in report.users"
                            :key="user.id"
                            class="border-b last:border-0"
                        >
                            <th scope="row" class="p-4 text-left font-medium">
                                {{ user.name }}
                            </th>
                            <td class="p-4 text-left">{{ user.email }}</td>
                            <td class="p-4">{{ user.generations }}</td>
                            <td class="p-4">{{ user.billed }}</td>
                            <td class="p-4">{{ user.cost }}</td>
                            <td class="p-4">{{ user.margin }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
