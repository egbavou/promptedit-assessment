export type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';
    status_label: string;
    is_final: boolean;
    estimate: string;
    price: string | null;
    error_message: string | null;
    image_url: string | null;
    image_expired: boolean;
    created_at: string;
    show_url: string;
    pixelforge_id: string | null;
    started_at: string | null;
    completed_at: string | null;
    reference_images: string[];
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; per_page: number };
};

export type Breakdown = {
    lines: { label: string; tokens: number; rate: string; cost: string }[];
    provider_cost: string;
    marked_up: string;
    price: string;
};
