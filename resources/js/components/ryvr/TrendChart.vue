<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { computed, ref } from 'vue';
import {
    bucketLabel,
    bucketTitle,
    formatExact,
    formatNumber,
} from '@/lib/format';

export type SeriesPoint = {
    bucket: string;
    visitors: number;
    visits: number;
    pageviews: number;
    previous: {
        bucket: string;
        visitors: number;
        visits: number;
        pageviews: number;
    } | null;
};

const props = defineProps<{
    series: SeriesPoint[];
    metric: 'visitors' | 'visits' | 'pageviews';
    metricLabel: string;
    interval: string;
}>();

const HEIGHT = 220;
const TOP = 12;
const BOTTOM = 24;
const container = ref<HTMLElement | null>(null);
const width = ref(800);
const hover = ref<number | null>(null);

useResizeObserver(container, (entries) => {
    width.value = Math.max(280, entries[0].contentRect.width);
});

const values = computed(() => props.series.map((p) => p[props.metric]));
const previous = computed(() =>
    props.series.map((p) => p.previous?.[props.metric] ?? null),
);
const max = computed(() =>
    niceMax(Math.max(1, ...values.value, ...previous.value.map((v) => v ?? 0))),
);
const plotHeight = HEIGHT - TOP - BOTTOM;
const slot = computed(() => width.value / Math.max(1, props.series.length));
const barWidth = computed(() => Math.max(2, Math.min(28, slot.value * 0.62)));

const y = (v: number) => TOP + plotHeight - (v / max.value) * plotHeight;
const x = (i: number) => i * slot.value + slot.value / 2;

const previousPath = computed(() => {
    const points = previous.value.map((v, i) =>
        v === null ? null : `${x(i).toFixed(1)},${y(v).toFixed(1)}`,
    );
    if (points.every((p) => p === null)) return '';
    return 'M' + points.filter(Boolean).join(' L');
});

const ticks = computed(() => {
    const n = props.series.length;
    const wanted = Math.max(2, Math.min(7, Math.floor(width.value / 110)));
    const step = Math.max(1, Math.ceil(n / wanted));
    return props.series
        .map((p, i) => ({ i, label: bucketLabel(p.bucket, props.interval) }))
        .filter(({ i }) => i % step === 0);
});

const hovered = computed(() =>
    hover.value === null ? null : props.series[hover.value],
);
const tooltipLeft = computed(() => {
    if (hover.value === null) return 0;
    const px = x(hover.value);
    return Math.min(Math.max(px, 90), width.value - 90);
});

function niceMax(value: number): number {
    const exp = Math.pow(10, Math.floor(Math.log10(value)));
    const f = value / exp;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * exp;
}

function onMove(event: PointerEvent) {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const i = Math.floor((event.clientX - rect.left) / slot.value);
    hover.value = Math.min(props.series.length - 1, Math.max(0, i));
}

function onKey(event: KeyboardEvent) {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    const last = props.series.length - 1;
    const current = hover.value ?? last;
    hover.value = Math.min(
        last,
        Math.max(0, current + (event.key === 'ArrowRight' ? 1 : -1)),
    );
}

const total = computed(() => values.value.reduce((a, b) => a + b, 0));
</script>

<template>
    <div
        ref="container"
        class="relative outline-none select-none"
        tabindex="0"
        role="img"
        :aria-label="`${metricLabel} over time, ${formatExact(total)} in total. Use the arrow keys to read each point.`"
        @pointermove="onMove"
        @pointerleave="hover = null"
        @keydown="onKey"
        @blur="hover = null"
    >
        <svg :width="width" :height="HEIGHT" class="block">
            <line
                :x1="0"
                :x2="width"
                :y1="y(max)"
                :y2="y(max)"
                class="stroke-border"
                stroke-dasharray="2 4"
            />
            <line
                :x1="0"
                :x2="width"
                :y1="y(max / 2)"
                :y2="y(max / 2)"
                class="stroke-border"
                stroke-dasharray="2 4"
            />
            <text
                :x="0"
                :y="y(max) - 4"
                class="fill-muted-foreground text-[11px]"
            >
                {{ formatNumber(max) }}
            </text>

            <rect
                v-for="(v, i) in values"
                :key="series[i].bucket"
                :x="x(i) - barWidth / 2"
                :y="y(v)"
                :width="barWidth"
                :height="Math.max(0, TOP + plotHeight - y(v))"
                rx="1.5"
                :class="hover === i ? 'fill-river' : 'fill-river-soft'"
            />

            <path
                v-if="previousPath"
                :d="previousPath"
                class="fill-none stroke-muted-foreground/60"
                stroke-width="1.25"
                stroke-dasharray="3 3"
            />

            <line
                v-if="hover !== null"
                :x1="x(hover)"
                :x2="x(hover)"
                :y1="TOP"
                :y2="TOP + plotHeight"
                class="stroke-foreground/30"
            />

            <line
                :x1="0"
                :x2="width"
                :y1="TOP + plotHeight"
                :y2="TOP + plotHeight"
                class="stroke-border"
            />
            <text
                v-for="tick in ticks"
                :key="tick.i"
                :x="x(tick.i)"
                :y="HEIGHT - 6"
                text-anchor="middle"
                class="fill-muted-foreground text-[11px]"
            >
                {{ tick.label }}
            </text>
        </svg>

        <div
            v-if="hovered"
            class="pointer-events-none absolute top-0 z-10 w-44 -translate-x-1/2 rounded-md bg-foreground px-3 py-2 text-xs text-background shadow-sm"
            :style="{ left: `${tooltipLeft}px` }"
        >
            <div class="opacity-70">
                {{ bucketTitle(hovered.bucket, interval) }}
            </div>
            <div class="mt-1 flex items-baseline justify-between gap-3">
                <span>{{ metricLabel }}</span>
                <span class="text-sm font-medium">{{
                    formatExact(hovered[metric])
                }}</span>
            </div>
            <div
                v-if="hovered.previous"
                class="mt-0.5 flex items-baseline justify-between gap-3 opacity-70"
            >
                <span>Previous</span>
                <span>{{ formatExact(hovered.previous[metric]) }}</span>
            </div>
        </div>
    </div>
</template>
