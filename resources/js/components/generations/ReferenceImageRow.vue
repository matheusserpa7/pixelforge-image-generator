<script setup lang="ts">
import { X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps<{
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
                v-model="model"
                type="url"
                placeholder="https://example.com/image.png"
                :aria-label="`Reference image ${number}`"
            />
            <Button
                type="button"
                variant="outline"
                size="icon"
                :aria-label="`Remove reference image ${number}`"
                @click="$emit('remove')"
            >
                <X />
            </Button>
        </div>
        <InputError :message="error" />
    </div>
</template>
