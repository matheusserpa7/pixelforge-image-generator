<script setup lang="ts">
import { ImageOff, RotateCcw } from '@lucide/vue';
import { useNow } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

defineEmits<{ reuse: [generation: Generation] }>();

const imageFailed = ref(false);
const now = useNow({ interval: 30_000 });

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

const dimensions = computed(() => {
    const [width, height] = props.generation.size.split('x').map(Number);

    return width && height ? { width, height } : { width: 1, height: 1 };
});

const aspectRatio = computed(
    () => `${dimensions.value.width} / ${dimensions.value.height}`,
);

const sizeLabel = computed(() => props.generation.size.replace('x', '×'));

const createdAt = computed(() => new Date(props.generation.created_at));

const absoluteTime = computed(() =>
    new Intl.DateTimeFormat(document.documentElement.lang || 'en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(createdAt.value),
);

const relativeTime = computed(() => {
    const seconds = Math.round(
        (createdAt.value.getTime() - now.value.getTime()) / 1000,
    );
    const formatter = new Intl.RelativeTimeFormat(
        document.documentElement.lang || 'en',
        { numeric: 'auto' },
    );
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

    return 'just now';
});

const referencesLabel = computed(() => {
    const count = props.generation.reference_image_count;

    return `${count} ${count === 1 ? 'reference' : 'references'}`;
});

const isFailed = computed(() => props.generation.status === 'failed');

const showImage = computed(
    () => props.generation.image_url !== null && !imageFailed.value,
);

const placeholder = computed(() => {
    if (isFailed.value) {
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
        <a
            v-if="showImage"
            :href="generation.image_url ?? undefined"
            target="_blank"
            rel="noopener"
            class="block bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none focus-visible:ring-inset"
            :style="{ aspectRatio }"
            aria-label="Open full-size image in a new tab"
        >
            <img
                :src="generation.image_url ?? undefined"
                :alt="generation.prompt"
                :width="dimensions.width"
                :height="dimensions.height"
                loading="lazy"
                decoding="async"
                class="size-full object-cover"
                @error="imageFailed = true"
            />
        </a>
        <div
            v-else-if="isFailed"
            class="flex h-24 items-center justify-center gap-2 bg-muted text-sm text-muted-foreground"
        >
            <ImageOff class="size-4" aria-hidden="true" />
            {{ placeholder }}
        </div>
        <div
            v-else
            class="flex flex-col items-center justify-center gap-2 bg-muted text-sm text-muted-foreground"
            :style="{ aspectRatio }"
        >
            <template v-if="placeholder">
                <ImageOff class="size-5" aria-hidden="true" />
                {{ placeholder }}
            </template>
            <Spinner v-else class="size-6" aria-label="Generating image" />
        </div>

        <div class="flex flex-1 flex-col gap-2 p-4 text-sm">
            <div class="flex items-center justify-between gap-2">
                <div aria-live="polite">
                    <Badge :variant="statusVariants[generation.status]">
                        <Spinner
                            v-if="!generation.is_final"
                            class="size-3"
                            aria-hidden="true"
                        />
                        {{ statusLabels[generation.status] }}
                    </Badge>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="-my-1 -mr-2 h-7 shrink-0 px-2 text-xs"
                    @click="$emit('reuse', generation)"
                >
                    <RotateCcw aria-hidden="true" />
                    Reuse prompt
                </Button>
            </div>

            <p
                v-if="generation.awaiting_confirmation"
                class="text-muted-foreground"
            >
                Still confirming with the image service. You are only charged if
                it completes.
            </p>
            <p
                v-else-if="generation.status === 'queued'"
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
            <p v-else-if="isFailed" class="text-muted-foreground">
                {{ generation.error_message ?? 'The generation failed.' }}
            </p>

            <div class="tabular-nums">
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
                <template v-else-if="isFailed">
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

            <p class="line-clamp-3 wrap-anywhere" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p class="mt-auto text-xs text-muted-foreground">
                {{ sizeLabel }} · {{ generation.quality }} ·
                {{ referencesLabel }} ·
                <time :datetime="generation.created_at" :title="absoluteTime">{{
                    relativeTime
                }}</time>
            </p>
        </div>
    </article>
</template>
