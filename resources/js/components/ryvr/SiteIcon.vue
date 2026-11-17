<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ domain: string; size?: number }>(), {
    size: 16,
});

const failed = ref(false);
watch(
    () => props.domain,
    () => (failed.value = false),
);

const clean = computed(
    () =>
        props.domain
            .toLowerCase()
            .replace(/^https?:\/\//, '')
            .replace(/^www\./, '')
            .split('/')[0],
);
const valid = computed(() => /^([a-z0-9-]+\.)+[a-z]{2,}$/.test(clean.value));
const letter = computed(() => (clean.value[0] ?? '?').toUpperCase());
</script>

<template>
    <img
        v-if="valid && !failed"
        :src="`/icons/${clean}`"
        :width="size"
        :height="size"
        alt=""
        loading="lazy"
        decoding="async"
        class="shrink-0 rounded-[3px] object-contain"
        :style="{ width: `${size}px`, height: `${size}px` }"
        @error="failed = true"
    />
    <span
        v-else
        class="inline-flex shrink-0 items-center justify-center rounded-[3px] bg-muted text-[9px] font-medium text-muted-foreground"
        :style="{ width: `${size}px`, height: `${size}px` }"
        aria-hidden="true"
        >{{ letter }}</span
    >
</template>
