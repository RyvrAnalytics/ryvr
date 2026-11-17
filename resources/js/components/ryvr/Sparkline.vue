<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{ values: number[]; width?: number; height?: number }>(),
    {
        width: 120,
        height: 28,
    },
);

const path = computed(() => {
    const values = props.values;
    if (values.length < 2) return '';
    const max = Math.max(...values, 1);
    const step = props.width / (values.length - 1);
    return values
        .map(
            (v, i) =>
                `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(1)},${(props.height - 2 - (v / max) * (props.height - 4)).toFixed(1)}`,
        )
        .join(' ');
});

const area = computed(() =>
    path.value
        ? `${path.value} L${props.width},${props.height} L0,${props.height} Z`
        : '',
);
</script>

<template>
    <svg
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        aria-hidden="true"
        class="block overflow-visible"
    >
        <path v-if="area" :d="area" class="fill-river-faint" />
        <path
            v-if="path"
            :d="path"
            class="fill-none stroke-river"
            stroke-width="1.25"
            stroke-linejoin="round"
        />
        <line
            v-else
            x1="0"
            :x2="width"
            :y1="height - 1"
            :y2="height - 1"
            class="stroke-border"
            stroke-width="1"
        />
    </svg>
</template>
