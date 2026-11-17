<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';
import GenerateForm from '@/components/generations/GenerateForm.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import { index } from '@/routes/generations';
import type { Generation, GenerationOptions, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    options: GenerationOptions;
    maxReferenceImages: number;
    maxPromptLength: number;
}>();

const { start, stop } = usePoll(
    3000,
    { only: ['generations'] },
    { autoStart: false },
);

watch(
    () => props.generations.data,
    (items) => {
        if (items.some((item) => !item.is_final)) {
            start();
        } else {
            stop();
        }
    },
    { immediate: true },
);

function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generations', href: index() }],
    },
});
</script>

<template>
    <Head title="Generations" />

    <div class="flex flex-col gap-6 p-4">
        <GenerateForm
            :options="options"
            :max-reference-images="maxReferenceImages"
            :max-prompt-length="maxPromptLength"
        />

        <p
            v-if="generations.data.length === 0"
            class="py-8 text-center text-sm text-muted-foreground"
        >
            No generations yet. Write a prompt above to create your first image.
        </p>

        <div
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <nav
            v-if="generations.meta.links.length > 3"
            class="flex flex-wrap justify-center gap-1"
            aria-label="Pagination"
        >
            <template v-for="link in generations.meta.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                >
                    {{ pageLabel(link.label) }}
                </Link>
                <span
                    v-else
                    class="rounded-md border px-3 py-1 text-sm text-muted-foreground opacity-50"
                    >{{ pageLabel(link.label) }}</span
                >
            </template>
        </nav>
    </div>
</template>
