import type { MoneyView } from './generations';

export type CreditBalanceView = {
    available: MoneyView;
    balance: MoneyView;
    held: MoneyView;
    purchased: MoneyView;
    charged: MoneyView;
};

export type CreditPack = {
    value: string;
    label: string;
};

export type CreditPurchaseRow = {
    id: number;
    kind: 'purchase' | 'opening_balance';
    amount: MoneyView;
    created_at: string | null;
};
