<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        aria-hidden="true"
        :class="className"
        v-bind="$attrs"
    >
        <g fill="currentColor">
            <rect x="2" y="2" width="9" height="9" rx="2" />
            <rect x="13" y="2" width="9" height="9" rx="2" opacity="0.45" />
            <rect x="2" y="13" width="9" height="9" rx="2" opacity="0.45" />
            <rect x="13" y="13" width="9" height="9" rx="2" />
        </g>
    </svg>
</template>
