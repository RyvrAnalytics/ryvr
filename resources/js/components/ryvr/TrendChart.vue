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

const props = withDefaults(
    defineProps<{
        series: SeriesPoint[];
        metric: 'visitors' | 'visits' | 'pageviews';
        metricLabel: string;
        interval: string;
        height?: number;
    }>(),
    { height: 240 },
);

const TOP = 16;
const BOTTOM = 26;
const RIGHT = 36;
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
const plotWidth = computed(() => width.value - RIGHT);
const plotHeight = computed(() => props.height - TOP - BOTTOM);
const step = computed(
    () => plotWidth.value / Math.max(1, props.series.length - 1),
);

const x = (i: number) =>
    props.series.length === 1 ? plotWidth.value / 2 : i * step.value;
const y = (v: number) =>
    TOP + plotHeight.value - (v / max.value) * plotHeight.value;

/** A smooth line through the points, so trends read as flow. */
function smooth(points: [number, number][]): string {
    if (points.length === 0) return '';
    let d = `M${points[0][0].toFixed(1)},${points[0][1].toFixed(1)}`;
    for (let i = 1; i < points.length; i++) {
        const [x0, y0] = points[i - 1];
        const [x1, y1] = points[i];
        const cx = (x0 + x1) / 2;
        d += ` C${cx.toFixed(1)},${y0.toFixed(1)} ${cx.toFixed(1)},${y1.toFixed(1)} ${x1.toFixed(1)},${y1.toFixed(1)}`;
    }
    return d;
}

const line = computed(() =>
    smooth(values.value.map((v, i) => [x(i), y(v)] as [number, number])),
);
const area = computed(() =>
    line.value && values.value.length > 1
        ? `${line.value} L${x(values.value.length - 1).toFixed(1)},${TOP + plotHeight.value} L${x(0).toFixed(1)},${TOP + plotHeight.value} Z`
        : '',
);
const previousLine = computed(() => {
    const pts = previous.value
        .map((v, i) => (v === null ? null : ([x(i), y(v)] as [number, number])))
        .filter((p): p is [number, number] => p !== null);
    return pts.length > 1 ? smooth(pts) : '';
});

const ticks = computed(() => {
    const n = props.series.length;
    const wanted = Math.max(2, Math.min(7, Math.floor(width.value / 120)));
    const every = Math.max(1, Math.ceil(n / wanted));
    return props.series
        .map((p, i) => ({ i, label: bucketLabel(p.bucket, props.interval) }))
        .filter(({ i }) => i % every === 0);
});

const hovered = computed(() =>
    hover.value === null ? null : props.series[hover.value],
);
const tooltipLeft = computed(() =>
    hover.value === null
        ? 0
        : Math.min(Math.max(x(hover.value), 96), width.value - 96),
);

function niceMax(value: number): number {
    const exp = Math.pow(10, Math.floor(Math.log10(value)));
    const f = value / exp;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * exp;
}

function onMove(event: PointerEvent) {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const i = Math.round((event.clientX - rect.left) / step.value);
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
        <svg :width="width" :height="height" class="block overflow-visible">
            <line
                :x1="0"
                :x2="plotWidth"
                :y1="y(max / 2)"
                :y2="y(max / 2)"
                class="stroke-border"
                stroke-dasharray="1 5"
                stroke-linecap="round"
            />
            <text
                :x="plotWidth + 8"
                :y="y(max) + 4"
                class="fill-muted-foreground text-[11px]"
            >
                {{ formatNumber(max) }}
            </text>
            <text
                :x="plotWidth + 8"
                :y="y(max / 2) + 4"
                class="fill-muted-foreground text-[11px]"
            >
                {{ formatNumber(max / 2) }}
            </text>

            <path v-if="area" :d="area" class="fill-river-faint" />
            <path
                v-if="previousLine"
                :d="previousLine"
                class="fill-none stroke-muted-foreground/50"
                stroke-width="1.25"
                stroke-dasharray="4 4"
            />
            <path
                v-if="line"
                :d="line"
                class="fill-none stroke-river"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <line
                :x1="0"
                :x2="plotWidth"
                :y1="TOP + plotHeight"
                :y2="TOP + plotHeight"
                class="stroke-border"
            />

            <template v-if="hover !== null">
                <line
                    :x1="x(hover)"
                    :x2="x(hover)"
                    :y1="TOP"
                    :y2="TOP + plotHeight"
                    class="stroke-foreground/25"
                />
                <circle
                    :cx="x(hover)"
                    :cy="y(values[hover])"
                    r="4.5"
                    class="fill-card stroke-river"
                    stroke-width="2"
                />
            </template>

            <text
                v-for="tick in ticks"
                :key="tick.i"
                :x="x(tick.i)"
                :y="height - 6"
                :text-anchor="tick.i === 0 ? 'start' : 'middle'"
                class="fill-muted-foreground text-[11px]"
            >
                {{ tick.label }}
            </text>
        </svg>

        <div
            v-if="hovered"
            class="pointer-events-none absolute -top-2 z-10 w-48 -translate-x-1/2 rounded-lg bg-foreground px-3 py-2 text-xs text-background shadow-sm"
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
                <span>Previous period</span>
                <span>{{ formatExact(hovered.previous[metric]) }}</span>
            </div>
        </div>
    </div>
</template>
