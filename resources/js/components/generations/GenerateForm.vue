<script setup lang="ts">
import { Link, useForm, useHttp } from '@inertiajs/vue3';
import { CircleAlert, Plus } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import {
    estimate,
    store,
} from '@/actions/App/Http/Controllers/GenerationController';
import ReferenceImageRow from '@/components/generations/ReferenceImageRow.vue';
import InputError from '@/components/InputError.vue';
import { index as creditsIndex } from '@/routes/credits';
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
import type { EstimateResponse, Generation, GenerationOptions } from '@/types';

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

const formElement = ref<HTMLFormElement | null>(null);
const promptElement = ref<HTMLTextAreaElement | null>(null);
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
    () =>
        form.processing ||
        pricingUnavailable.value !== null ||
        currentEstimate.value?.affordable === false,
);

const bannerErrors = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return [errors.pricing, errors.generation, errors.credits].filter(
        (message): message is string => typeof message === 'string',
    );
});

const submitShortcut = /Mac|iPhone|iPad/.test(navigator.userAgent)
    ? '⌘ Enter'
    : 'Ctrl + Enter';

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

function referenceInputId(position: number): string {
    return `reference-image-${position + 1}`;
}

async function addReference(): Promise<void> {
    if (form.reference_images.length >= props.maxReferenceImages) {
        return;
    }

    form.reference_images.push('');
    await nextTick();
    document
        .getElementById(referenceInputId(form.reference_images.length - 1))
        ?.focus();
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

function focusFirstError(): void {
    const errors = form.errors as Record<string, string | undefined>;

    if (errors.prompt) {
        promptElement.value?.focus();

        return;
    }

    const referenceIndex = form.reference_images.findIndex(
        (_url, index) => referenceError(index) !== undefined,
    );

    if (referenceIndex !== -1) {
        document.getElementById(referenceInputId(referenceIndex))?.focus();
    }
}

function submit(): void {
    if (submitDisabled.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images
            .map((url) => url.trim())
            .filter((url) => url !== ''),
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => nextTick(focusFirstError),
    });
}

/**
 * Fills the form with a previous generation's prompt and options so the user
 * can retry or iterate without retyping. Reference image URLs are not kept
 * on generations, so they are left as they are.
 */
async function reuse(generation: Generation): Promise<void> {
    form.clearErrors();
    form.prompt = generation.prompt;
    form.size = generation.size;
    form.quality = generation.quality;

    await nextTick();
    formElement.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    promptElement.value?.focus({ preventScroll: true });
}

defineExpose({ reuse });
</script>

<template>
    <form
        ref="formElement"
        class="scroll-mt-4 space-y-5 rounded-xl border p-4 md:p-6"
        aria-labelledby="generate-heading"
        @submit.prevent="submit"
    >
        <h2 id="generate-heading" class="text-base font-semibold">New image</h2>

        <Alert
            v-for="message in bannerErrors"
            :key="message"
            variant="destructive"
        >
            <CircleAlert aria-hidden="true" />
            <AlertDescription>{{ message }}</AlertDescription>
        </Alert>
        <Alert v-if="pricingUnavailable" variant="destructive">
            <CircleAlert aria-hidden="true" />
            <AlertDescription>{{ pricingUnavailable }}</AlertDescription>
        </Alert>

        <div class="grid gap-2">
            <Label for="prompt">Prompt</Label>
            <textarea
                id="prompt"
                ref="promptElement"
                v-model="form.prompt"
                name="prompt"
                autocomplete="off"
                :maxlength="maxPromptLength"
                rows="4"
                placeholder="A lighthouse at dusk, oil painting…"
                :aria-invalid="form.errors.prompt ? true : undefined"
                aria-describedby="prompt-hint"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                @keydown.meta.enter.prevent="submit"
                @keydown.ctrl.enter.prevent="submit"
            ></textarea>
            <div class="flex justify-between gap-2">
                <InputError :message="form.errors.prompt" />
                <span
                    id="prompt-hint"
                    class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                >
                    {{ form.prompt.length }} / {{ maxPromptLength }}
                    <span class="sr-only">characters</span>
                </span>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="size">Size</Label>
                <Select v-model="form.size" name="size">
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
                <Select v-model="form.quality" name="quality">
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

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm leading-none font-medium">
                Reference images
                <span class="font-normal text-muted-foreground">
                    (optional, up to {{ maxReferenceImages }} URLs)
                </span>
            </legend>
            <ReferenceImageRow
                v-for="position in referencePositions"
                :id="referenceInputId(position)"
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
                    <Plus aria-hidden="true" />
                    Add reference image
                </Button>
            </div>
        </fieldset>

        <div
            class="space-y-1 rounded-lg bg-muted/50 p-4 text-sm"
            aria-live="polite"
        >
            <div
                class="flex flex-wrap items-center gap-x-6 gap-y-1 tabular-nums"
            >
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
                    <span>
                        Available
                        <strong :title="currentEstimate.available.amount">{{
                            currentEstimate.available.display
                        }}</strong>
                    </span>
                </template>
                <span v-else class="text-muted-foreground"
                    >Estimating cost…</span
                >
                <Spinner
                    v-if="estimateLoading"
                    class="size-4"
                    aria-label="Updating estimate"
                />
            </div>
            <p
                v-if="currentEstimate && !currentEstimate.affordable"
                class="flex items-start gap-2 pt-1 font-medium text-red-700 dark:text-red-400"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    Your available credit doesn’t cover the maximum charge.
                    <Link
                        :href="creditsIndex()"
                        class="underline underline-offset-4 hover:no-underline focus-visible:rounded-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >Buy credits</Link
                    >
                    to continue.
                </span>
            </p>
            <p v-if="estimatePaused" class="text-xs text-muted-foreground">
                Estimate paused, try again in a moment.
            </p>
            <p class="text-xs text-muted-foreground">
                You pay the actual cost once the image is ready, never more than
                the maximum unless Pixelforge changes its prices. Failed
                generations are free.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="submitDisabled">
                <Spinner v-if="form.processing" />
                {{ form.processing ? 'Submitting…' : 'Generate' }}
            </Button>
            <span
                class="hidden text-xs text-muted-foreground sm:inline"
                aria-hidden="true"
            >
                or press {{ submitShortcut }}
            </span>
        </div>
    </form>
</template>
