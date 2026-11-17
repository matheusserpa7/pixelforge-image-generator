<script setup lang="ts">
import { X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps<{
    id: string;
    number: number;
    error?: string;
}>();

const model = defineModel<string>({ required: true });

defineEmits<{ remove: [] }>();
</script>

<template>
    <div class="grid gap-1">
        <div class="flex gap-2">
            <Input
                :id="id"
                v-model="model"
                type="url"
                inputmode="url"
                :name="`reference_images[${number - 1}]`"
                autocomplete="off"
                spellcheck="false"
                placeholder="https://example.com/image.png"
                :aria-label="`Reference image ${number} URL`"
                :aria-invalid="error ? true : undefined"
            />
            <Button
                type="button"
                variant="outline"
                size="icon"
                class="shrink-0"
                :aria-label="`Remove reference image ${number}`"
                @click="$emit('remove')"
            >
                <X aria-hidden="true" />
            </Button>
        </div>
        <InputError :message="error" />
    </div>
</template>
