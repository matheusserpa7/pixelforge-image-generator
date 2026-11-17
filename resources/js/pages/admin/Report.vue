<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Label } from '@/components/ui/label';
import { report } from '@/routes/admin';
import type { ReportDay, ReportTotals, ReportUser } from '@/types';

const props = defineProps<{
    range: { start: string; end: string };
    providerUsageAvailable: boolean;
    totals: ReportTotals;
    days: ReportDay[];
    users: ReportUser[];
    errors?: Record<string, string>;
}>();

const start = ref(props.range.start);
const applying = ref(false);
const end = ref(props.range.end);

const daysNewestFirst = computed(() => [...props.days].reverse());
const differingDays = computed(
    () => props.days.filter((day) => day.matches === false).length,
);

function apply(): void {
    router.get(
        report.url(),
        { start: start.value, end: end.value },
        {
            preserveScroll: true,
            onStart: () => {
                applying.value = true;
            },
            onFinish: () => {
                applying.value = false;
            },
        },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Report', href: report() }],
    },
});
</script>

<template>
    <Head title="Report" />

    <div class="flex flex-col gap-6 p-4">
        <h1 class="sr-only">Report</h1>

        <form class="flex flex-wrap items-end gap-4" @submit.prevent="apply">
            <div class="grid gap-2">
                <Label for="report-start">Start (UTC)</Label>
                <Input
                    id="report-start"
                    v-model="start"
                    type="date"
                    name="start"
                    :aria-invalid="errors?.start ? true : undefined"
                />
                <p
                    v-if="errors?.start"
                    class="text-sm text-red-700 dark:text-red-400"
                >
                    {{ errors.start }}
                </p>
            </div>
            <div class="grid gap-2">
                <Label for="report-end">End (UTC)</Label>
                <Input
                    id="report-end"
                    v-model="end"
                    type="date"
                    name="end"
                    :aria-invalid="errors?.end ? true : undefined"
                />
                <p
                    v-if="errors?.end"
                    class="text-sm text-red-700 dark:text-red-400"
                >
                    {{ errors.end }}
                </p>
            </div>
            <Button type="submit" :disabled="applying">
                <Spinner v-if="applying" />
                Apply range
            </Button>
        </form>

        <Alert v-if="!providerUsageAvailable">
            <AlertTitle>Pixelforge usage unavailable</AlertTitle>
            <AlertDescription>
                Pixelforge usage unavailable right now; showing our own figures.
            </AlertDescription>
        </Alert>
        <Alert
            v-else-if="differingDays === 0"
            class="border-green-600/40 bg-green-50 text-green-900 dark:bg-green-950 dark:text-green-100"
        >
            <AlertTitle>Matches Pixelforge for every day</AlertTitle>
            <AlertDescription>
                Our provider cost equals Pixelforge's usage to the nano.
            </AlertDescription>
        </Alert>
        <Alert
            v-else
            class="border-amber-600/40 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100"
        >
            <AlertTitle>
                {{ differingDays }}
                {{ differingDays === 1 ? 'day differs' : 'days differ' }} from
                Pixelforge
            </AlertTitle>
            <AlertDescription>
                Usually a generation succeeded at Pixelforge but our record has
                not caught up yet; reconciliation will apply it.
            </AlertDescription>
        </Alert>

        <div class="grid gap-4 tabular-nums sm:grid-cols-2 lg:grid-cols-5">
            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium"
                        >Generations</CardTitle
                    >
                </CardHeader>
                <CardContent class="text-2xl font-semibold">
                    {{ totals.generations }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Charged</CardTitle>
                </CardHeader>
                <CardContent
                    class="text-2xl font-semibold"
                    :title="totals.charged.amount"
                >
                    {{ totals.charged.display }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium"
                        >Provider cost</CardTitle
                    >
                </CardHeader>
                <CardContent
                    class="text-2xl font-semibold"
                    :title="totals.providerCost.amount"
                >
                    {{ totals.providerCost.display }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Profit</CardTitle>
                </CardHeader>
                <CardContent
                    class="text-2xl font-semibold"
                    :title="totals.profit.amount"
                >
                    {{ totals.profit.display }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium"
                        >Pixelforge cost</CardTitle
                    >
                </CardHeader>
                <CardContent
                    class="text-2xl font-semibold"
                    :title="totals.pixelforgeCost?.amount"
                >
                    {{ totals.pixelforgeCost?.display ?? 'Unavailable' }}
                </CardContent>
            </Card>
        </div>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">Per day</h2>
            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm tabular-nums">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                Date
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Generations
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Charged
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Provider cost
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Profit
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Pixelforge cost
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Difference
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in daysNewestFirst"
                            :key="day.date"
                            class="border-t"
                            :class="
                                day.matches === false
                                    ? 'bg-amber-50 dark:bg-amber-950'
                                    : ''
                            "
                        >
                            <td class="px-3 py-2">{{ day.date }}</td>
                            <td class="px-3 py-2 text-right">
                                {{ day.generations }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="day.charged.amount"
                            >
                                {{ day.charged.display }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="day.providerCost.amount"
                            >
                                {{ day.providerCost.display }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="day.profit.amount"
                            >
                                {{ day.profit.display }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="day.pixelforgeCost?.amount"
                            >
                                {{ day.pixelforgeCost?.display ?? '' }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :class="
                                    day.matches === false
                                        ? 'font-semibold text-amber-800 dark:text-amber-200'
                                        : ''
                                "
                                :title="day.difference?.amount"
                            >
                                <template v-if="day.matches === false">
                                    {{ day.difference?.amount }}
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">Per user</h2>
            <p
                v-if="users.length === 0"
                class="py-4 text-center text-sm text-muted-foreground"
            >
                No charges in this range.
            </p>
            <div v-else class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm tabular-nums">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                User
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Generations
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Charged
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Provider cost
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.userId ?? 'deleted'"
                            class="border-t"
                        >
                            <td class="px-3 py-2">
                                <div>{{ user.name }}</div>
                                <div
                                    v-if="user.email"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ user.email }}
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ user.generations }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="user.charged.amount"
                            >
                                {{ user.charged.display }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="user.providerCost.amount"
                            >
                                {{ user.providerCost.display }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="user.profit.amount"
                            >
                                {{ user.profit.display }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
