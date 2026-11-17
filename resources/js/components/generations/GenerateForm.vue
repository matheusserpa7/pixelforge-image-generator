<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    estimate,
    store,
} from '@/actions/App/Http/Controllers/GenerationController';
import ReferenceImageRow from '@/components/generations/ReferenceImageRow.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { EstimateResponse, GenerationOptions } from '@/types';

const props = defineProps<{
    options: GenerationOptions;
    maxReferenceImages: number;
    maxPromptLength: number;
}>();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const estimateHttp = useHttp({
    prompt: '',
    size: '',
    quality: '',
    reference_images: [] as string[],
});

const currentEstimate = ref<EstimateResponse | null>(null);
const estimateLoading = ref(false);
const pricingUnavailable = ref<string | null>(null);
const estimatePaused = ref(false);

const filledReferences = computed(() =>
    form.reference_images.map((url) => url.trim()).filter((url) => url !== ''),
);

const referencePositions = computed(() =>
    form.reference_images.map((_url, position) => position),
);

const submitDisabled = computed(
    () => form.processing || pricingUnavailable.value !== null,
);

const bannerErrors = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return [errors.pricing, errors.generation].filter(
        (message): message is string => typeof message === 'string',
    );
});

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function requestEstimate(): void {
    estimateHttp.cancel();
    estimateHttp.prompt = form.prompt;
    estimateHttp.size = form.size;
    estimateHttp.quality = form.quality;
    estimateHttp.reference_images = filledReferences.value;
    estimateLoading.value = true;

    estimateHttp
        .post(estimate.url(), {
            onSuccess: (response) => {
                currentEstimate.value = response as EstimateResponse;
                pricingUnavailable.value = null;
                estimatePaused.value = false;
            },
            onHttpException: (response) => {
                if (response.status === 503) {
                    let message =
                        'Pricing is unavailable. Try again in a moment.';

                    try {
                        const body = JSON.parse(response.data) as {
                            message?: string;
                        };
                        message = body.message ?? message;
                    } catch {
                        // Keep the default message.
                    }

                    pricingUnavailable.value = message;
                    estimatePaused.value = false;
                } else if (response.status === 429) {
                    estimatePaused.value = true;
                }

                return false;
            },
            onFinish: () => {
                estimateLoading.value = false;
            },
        })
        .catch(() => {
            // Handled through the callbacks above; cancelled requests land here too.
        });
}

watch(
    () => [form.prompt, form.size, form.quality, filledReferences.value.length],
    () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(requestEstimate, 400);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearTimeout(debounceTimer);
    estimateHttp.cancel();
});

function addReference(): void {
    if (form.reference_images.length < props.maxReferenceImages) {
        form.reference_images.push('');
    }
}

function setReference(position: number, value: string): void {
    form.reference_images[position] = value;
}

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);
}

function referenceError(index: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images
            .map((url) => url.trim())
            .filter((url) => url !== ''),
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form
        class="space-y-5 rounded-xl border p-4 md:p-6"
        @submit.prevent="submit"
    >
        <Alert
            v-for="message in bannerErrors"
            :key="message"
            variant="destructive"
        >
            <AlertDescription>{{ message }}</AlertDescription>
        </Alert>
        <Alert v-if="pricingUnavailable" variant="destructive">
            <AlertDescription>{{ pricingUnavailable }}</AlertDescription>
        </Alert>

        <div class="grid gap-2">
            <Label for="prompt">Prompt</Label>
            <textarea
                id="prompt"
                v-model="form.prompt"
                :maxlength="maxPromptLength"
                rows="4"
                placeholder="Describe the image you want"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            ></textarea>
            <div class="flex justify-between gap-2">
                <InputError :message="form.errors.prompt" />
                <span class="ml-auto text-xs text-muted-foreground">
                    {{ form.prompt.length }} / {{ maxPromptLength }}
                </span>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="size">Size</Label>
                <Select v-model="form.size">
                    <SelectTrigger id="size" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in options.sizes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.size" />
            </div>
            <div class="grid gap-2">
                <Label for="quality">Quality</Label>
                <Select v-model="form.quality">
                    <SelectTrigger id="quality" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in options.qualities"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.quality" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label>Reference images (URLs)</Label>
            <ReferenceImageRow
                v-for="position in referencePositions"
                :key="position"
                :model-value="form.reference_images[position] ?? ''"
                :number="position + 1"
                :error="referenceError(position)"
                @update:model-value="(value) => setReference(position, value)"
                @remove="removeReference(position)"
            />
            <InputError :message="form.errors.reference_images" />
            <div v-if="form.reference_images.length < maxReferenceImages">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addReference"
                >
                    <Plus />
                    Add reference image
                </Button>
            </div>
        </div>

        <div class="space-y-1 rounded-lg bg-muted/50 p-4 text-sm">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-1">
                <template v-if="currentEstimate">
                    <span>
                        Estimated cost
                        <strong
                            :title="currentEstimate.estimated_charge.amount"
                            >{{
                                currentEstimate.estimated_charge.display
                            }}</strong
                        >
                    </span>
                    <span>
                        Maximum
                        <strong
                            :title="currentEstimate.maximum_charge.amount"
                            >{{
                                currentEstimate.maximum_charge.display
                            }}</strong
                        >
                    </span>
                </template>
                <span v-else class="text-muted-foreground"
                    >Estimating cost...</span
                >
                <Spinner v-if="estimateLoading" class="size-4" />
            </div>
            <p v-if="estimatePaused" class="text-xs text-muted-foreground">
                Estimate paused, try again in a moment.
            </p>
            <p class="text-xs text-muted-foreground">
                You pay the actual cost once the image is ready, never more than
                the maximum unless Pixelforge changes its prices. Failed
                generations are free.
            </p>
        </div>

        <Button type="submit" :disabled="submitDisabled">
            <Spinner v-if="form.processing" />
            Generate
        </Button>
    </form>
</template>
