<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index, purchase } from '@/routes/credits';
import type { CreditBalanceView, CreditPack, CreditPurchaseRow } from '@/types';

const props = defineProps<{
    balance: CreditBalanceView;
    packs: CreditPack[];
    purchases: CreditPurchaseRow[];
    purchaseKey: string;
}>();

const form = useForm({
    pack: '',
    idempotency_key: props.purchaseKey,
});

const buyingPack = ref<string | null>(null);

function buy(pack: string): void {
    buyingPack.value = pack;

    form.transform(() => ({
        pack,
        idempotency_key: props.purchaseKey,
    })).post(purchase.url(), {
        preserveScroll: true,
        onFinish: () => {
            buyingPack.value = null;
        },
    });
}

function kindLabel(kind: CreditPurchaseRow['kind']): string {
    return kind === 'opening_balance' ? 'Opening balance' : 'Purchase';
}

const dateFormatter = new Intl.DateTimeFormat(
    document.documentElement.lang || 'en',
    {
        dateStyle: 'medium',
        timeStyle: 'short',
    },
);

function formatDate(value: string | null): string {
    return value === null ? '' : dateFormatter.format(new Date(value));
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});
</script>

<template>
    <Head title="Credits" />

    <div class="flex flex-col gap-6 p-4">
        <h1 class="sr-only">Credits</h1>

        <div class="grid gap-4 tabular-nums sm:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardDescription>Available</CardDescription>
                    <CardTitle
                        class="text-2xl"
                        :title="balance.available.amount"
                        >{{ balance.available.display }}</CardTitle
                    >
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Balance</CardDescription>
                    <CardTitle
                        class="text-2xl"
                        :title="balance.balance.amount"
                        >{{ balance.balance.display }}</CardTitle
                    >
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription
                        >Held by generations in progress</CardDescription
                    >
                    <CardTitle class="text-2xl" :title="balance.held.amount">{{
                        balance.held.display
                    }}</CardTitle>
                </CardHeader>
            </Card>
        </div>
        <p class="text-sm text-muted-foreground">
            Each generation in progress holds its maximum charge until it
            finishes. You are then charged the actual cost and the rest becomes
            available again.
        </p>

        <Card>
            <CardHeader>
                <CardTitle>Buy credits</CardTitle>
                <CardDescription
                    >Fake purchase for this demo: no payment is
                    taken.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-2">
                <div class="flex flex-wrap gap-3">
                    <Button
                        v-for="pack in packs"
                        :key="pack.value"
                        type="button"
                        :disabled="form.processing"
                        @click="buy(pack.value)"
                    >
                        <Spinner v-if="buyingPack === pack.value" />
                        Buy {{ pack.label }}
                    </Button>
                </div>
                <p
                    v-if="form.errors.pack || form.errors.idempotency_key"
                    class="text-sm text-red-700 dark:text-red-400"
                    role="alert"
                >
                    {{ form.errors.pack ?? form.errors.idempotency_key }}
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Recent purchases</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="purchases.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No purchases yet.
                </p>
                <table v-else class="w-full text-left text-sm tabular-nums">
                    <thead class="text-muted-foreground">
                        <tr>
                            <th scope="col" class="py-2 font-medium">Date</th>
                            <th scope="col" class="py-2 font-medium">Kind</th>
                            <th scope="col" class="py-2 text-right font-medium">
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in purchases"
                            :key="row.id"
                            class="border-t"
                        >
                            <td class="py-2">
                                {{ formatDate(row.created_at) }}
                            </td>
                            <td class="py-2">{{ kindLabel(row.kind) }}</td>
                            <td
                                class="py-2 text-right"
                                :title="row.amount.amount"
                            >
                                {{ row.amount.display }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
