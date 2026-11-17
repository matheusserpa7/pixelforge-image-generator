import type { MoneyView } from './generations';

export type ReportDay = {
    date: string;
    generations: number;
    charged: MoneyView;
    providerCost: MoneyView;
    profit: MoneyView;
    pixelforgeCost: MoneyView | null;
    difference: MoneyView | null;
    matches: boolean | null;
};

export type ReportUser = {
    userId: number | null;
    name: string;
    email: string | null;
    generations: number;
    charged: MoneyView;
    providerCost: MoneyView;
    profit: MoneyView;
};

export type ReportTotals = {
    generations: number;
    charged: MoneyView;
    providerCost: MoneyView;
    profit: MoneyView;
    pixelforgeCost: MoneyView | null;
    matches: boolean | null;
};

export type ReportStuckGeneration = {
    id: string;
    userEmail: string | null;
    status: string;
    providerId: string;
    createdAt: string;
};
