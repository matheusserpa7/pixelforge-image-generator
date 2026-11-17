export type MoneyView = {
    amount: string;
    display: string;
};

export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type Generation = {
    id: string;
    status: GenerationStatus;
    is_final: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_image_count: number;
    created_at: string;
    estimated_charge: MoneyView;
    maximum_charge: MoneyView;
    charge: MoneyView | null;
    error_message: string | null;
    image_url: string | null;
    image_expired: boolean;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    meta: {
        links: PaginationLink[];
    };
};

export type Option = {
    value: string;
    label: string;
};

export type GenerationOptions = {
    sizes: Option[];
    qualities: Option[];
};

export type EstimateResponse = {
    estimated_charge: MoneyView;
    maximum_charge: MoneyView;
};
