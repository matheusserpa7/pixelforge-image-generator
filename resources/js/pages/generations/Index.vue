<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { index } from '@/routes/generations';

defineProps<{
    generations: unknown;
    options: unknown;
    maxReferenceImages: number;
    maxPromptLength: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generations', href: index() }],
    },
});
</script>

<template>
    <Head title="Generations" />

    <div class="p-4">
        <pre class="text-xs">{{ generations }}</pre>
    </div>
</template>
