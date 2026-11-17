<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const imageFailed = ref(false);

watch(
    () => props.generation.image_url,
    () => {
        imageFailed.value = false;
    },
);

const statusLabels = {
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
} as const;

const statusVariants = {
    queued: 'secondary',
    processing: 'outline',
    succeeded: 'default',
    failed: 'destructive',
} as const;

const aspectRatio = computed(() => {
    const [width, height] = props.generation.size.split('x');

    return width && height ? `${width} / ${height}` : '1 / 1';
});

const sizeLabel = computed(() => props.generation.size.replace('x', '×'));

const createdAt = computed(() => new Date(props.generation.created_at));

const relativeTime = computed(() => {
    const seconds = Math.round((createdAt.value.getTime() - Date.now()) / 1000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(seconds, 'second');
});

const referencesLabel = computed(() => {
    const count = props.generation.reference_image_count;

    return `${count} ${count === 1 ? 'reference' : 'references'}`;
});

const showImage = computed(
    () => props.generation.image_url !== null && !imageFailed.value,
);

const placeholder = computed(() => {
    if (props.generation.status === 'failed') {
        return 'No image';
    }

    if (props.generation.image_expired) {
        return 'Image expired';
    }

    if (props.generation.status === 'succeeded') {
        return 'Image unavailable';
    }

    return null;
});
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border bg-card">
        <div
            class="flex items-center justify-center bg-muted text-sm text-muted-foreground"
            :style="{ aspectRatio }"
        >
            <img
                v-if="showImage"
                :src="generation.image_url ?? undefined"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full object-cover"
                @error="imageFailed = true"
            />
            <span v-else-if="placeholder">{{ placeholder }}</span>
            <Spinner v-else class="size-6" />
        </div>

        <div class="flex flex-1 flex-col gap-2 p-4 text-sm">
            <div class="flex items-center justify-between gap-2">
                <Badge :variant="statusVariants[generation.status]">
                    {{ statusLabels[generation.status] }}
                </Badge>
            </div>

            <p
                v-if="generation.status === 'queued'"
                class="text-muted-foreground"
            >
                Waiting for the image service. Busy periods take longer.
            </p>
            <p
                v-else-if="generation.status === 'processing'"
                class="text-muted-foreground"
            >
                Generating, usually 5 to 30 seconds.
            </p>
            <p
                v-else-if="generation.status === 'failed'"
                class="text-muted-foreground"
            >
                {{ generation.error_message }} No charge.
            </p>

            <div>
                <template
                    v-if="
                        generation.status === 'succeeded' && generation.charge
                    "
                >
                    <div class="font-medium">
                        Charged
                        <span :title="generation.charge.amount">{{
                            generation.charge.display
                        }}</span>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        Estimate was
                        <span :title="generation.estimated_charge.amount">{{
                            generation.estimated_charge.display
                        }}</span>
                    </div>
                </template>
                <template v-else-if="generation.status === 'failed'">
                    <div class="font-medium">No charge</div>
                </template>
                <template v-else>
                    <div class="font-medium">
                        Estimate
                        <span :title="generation.estimated_charge.amount">{{
                            generation.estimated_charge.display
                        }}</span>
                        · up to
                        <span :title="generation.maximum_charge.amount">{{
                            generation.maximum_charge.display
                        }}</span>
                    </div>
                </template>
            </div>

            <p class="line-clamp-3" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p class="mt-auto text-xs text-muted-foreground">
                {{ sizeLabel }} · {{ generation.quality }} ·
                {{ referencesLabel }} ·
                <time
                    :datetime="generation.created_at"
                    :title="createdAt.toLocaleString()"
                    >{{ relativeTime }}</time
                >
            </p>
        </div>
    </article>
</template>
