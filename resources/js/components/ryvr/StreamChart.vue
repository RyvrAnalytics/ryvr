<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { computed, ref } from 'vue';
import { formatExact, formatNumber } from '@/lib/format';

/**
 * Sites as currents in one river: stacked, smoothed daily visitors per site.
 * The largest sites get their own colour; the rest flow together as "Other sites".
 */
const props = withDefaults(
    defineProps<{
        sites: { id: string; name: string; sparkline: number[] }[];
        days: string[];
        height?: number;
    }>(),
    { height: 200 },
);

const COLOURS = [
    'fill-river',
    'fill-stream-2',
    'fill-stream-3',
    'fill-stream-4',
    'fill-stream-5',
];
const SWATCH = [
    'bg-river',
    'bg-stream-2',
    'bg-stream-3',
    'bg-stream-4',
    'bg-stream-5',
];
const TOP = 8;
const BOTTOM = 22;

const container = ref<HTMLElement | null>(null);
const width = ref(800);
const hover = ref<number | null>(null);
useResizeObserver(container, (entries) => {
    width.value = Math.max(280, entries[0].contentRect.width);
});

const layers = computed(() => {
    const ranked = [...props.sites]
        .map((s) => ({ ...s, total: s.sparkline.reduce((a, b) => a + b, 0) }))
        .filter((s) => s.total > 0)
        .sort((a, b) => b.total - a.total);
    const top = ranked
        .slice(0, 4)
        .map((s) => ({ name: s.name, values: s.sparkline }));
    const rest = ranked.slice(4);
    if (rest.length) {
        top.push({
            name:
                rest.length === 1 ? rest[0].name : `${rest.length} other sites`,
            values: props.days.map((_, i) =>
                rest.reduce((a, s) => a + (s.sparkline[i] ?? 0), 0),
            ),
        });
    }
    return top;
});

const totals = computed(() =>
    props.days.map((_, i) =>
        layers.value.reduce((a, l) => a + (l.values[i] ?? 0), 0),
    ),
);
const max = computed(() => Math.max(1, ...totals.value));
const n = computed(() => props.days.length);
const step = computed(() => width.value / Math.max(1, n.value - 1));
const plotHeight = computed(() => props.height - TOP - BOTTOM);
const x = (i: number) => i * step.value;
const y = (v: number) =>
    TOP + plotHeight.value - (v / max.value) * plotHeight.value;

function curve(points: [number, number][]): string {
    let d = '';
    points.forEach(([px, py], i) => {
        if (i === 0) {
            d += `L${px.toFixed(1)},${py.toFixed(1)}`;
            return;
        }
        const [x0, y0] = points[i - 1];
        const cx = (x0 + px) / 2;
        d += ` C${cx.toFixed(1)},${y0.toFixed(1)} ${cx.toFixed(1)},${py.toFixed(1)} ${px.toFixed(1)},${py.toFixed(1)}`;
    });
    return d;
}

const paths = computed(() => {
    const base = props.days.map(() => 0);
    return layers.value.map((layer) => {
        const lower = base.map((v, i) => [x(i), y(v)] as [number, number]);
        const upperValues = base.map((v, i) => v + (layer.values[i] ?? 0));
        const upper = upperValues.map(
            (v, i) => [x(i), y(v)] as [number, number],
        );
        upperValues.forEach((v, i) => (base[i] = v));
        const top = curve(upper).replace(/^L/, 'M');
        const bottom = curve([...lower].reverse());
        return `${top} ${bottom} Z`;
    });
});

const ticks = computed(() => {
    const wanted = Math.max(2, Math.min(6, Math.floor(width.value / 130)));
    const every = Math.max(1, Math.ceil(n.value / wanted));
    return props.days
        .map((d, i) => ({
            i,
            label: new Date(`${d}T00:00:00Z`).toLocaleDateString('en', {
                month: 'short',
                day: 'numeric',
                timeZone: 'UTC',
            }),
        }))
        .filter(({ i }) => i % every === 0);
});

function onMove(event: PointerEvent) {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    hover.value = Math.min(
        n.value - 1,
        Math.max(0, Math.round((event.clientX - rect.left) / step.value)),
    );
}

const tooltipLeft = computed(() =>
    hover.value === null
        ? 0
        : Math.min(Math.max(x(hover.value), 100), width.value - 100),
);
</script>

<template>
    <div>
        <div
            ref="container"
            class="relative select-none"
            role="img"
            :aria-label="`Daily visitors per site over ${days.length} days, ${formatExact(totals.reduce((a, b) => a + b, 0))} in total.`"
            @pointermove="onMove"
            @pointerleave="hover = null"
        >
            <svg :width="width" :height="height" class="block overflow-visible">
                <path
                    v-for="(d, i) in paths"
                    :key="i"
                    :d="d"
                    :class="COLOURS[i]"
                />
                <line
                    :x1="0"
                    :x2="width"
                    :y1="TOP + plotHeight"
                    :y2="TOP + plotHeight"
                    class="stroke-border"
                />
                <line
                    v-if="hover !== null"
                    :x1="x(hover)"
                    :x2="x(hover)"
                    :y1="TOP"
                    :y2="TOP + plotHeight"
                    class="stroke-foreground/30"
                />
                <text
                    v-for="t in ticks"
                    :key="t.i"
                    :x="x(t.i)"
                    :y="height - 5"
                    :text-anchor="t.i === 0 ? 'start' : 'middle'"
                    class="fill-muted-foreground text-[11px]"
                >
                    {{ t.label }}
                </text>
            </svg>
            <div
                v-if="hover !== null"
                class="pointer-events-none absolute top-0 z-10 w-52 -translate-x-1/2 rounded-lg bg-foreground px-3 py-2 text-xs text-background"
                :style="{ left: `${tooltipLeft}px` }"
            >
                <div class="mb-1 opacity-70">
                    {{
                        new Date(`${days[hover]}T00:00:00Z`).toLocaleDateString(
                            'en',
                            {
                                weekday: 'short',
                                month: 'short',
                                day: 'numeric',
                                timeZone: 'UTC',
                            },
                        )
                    }}
                </div>
                <div
                    v-for="(layer, i) in layers"
                    :key="layer.name"
                    class="flex items-center gap-2"
                >
                    <span class="size-2 rounded-sm" :class="SWATCH[i]" />
                    <span class="flex-1 truncate">{{ layer.name }}</span>
                    <span>{{ formatExact(layer.values[hover] ?? 0) }}</span>
                </div>
                <div
                    class="mt-1 flex justify-between border-t border-background/20 pt-1"
                >
                    <span>Sum of sites</span>
                    <span class="font-medium">{{
                        formatExact(totals[hover])
                    }}</span>
                </div>
            </div>
        </div>
        <ul
            class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <li
                v-for="(layer, i) in layers"
                :key="layer.name"
                class="inline-flex items-center gap-1.5"
            >
                <span
                    class="size-2 rounded-sm"
                    :class="SWATCH[i]"
                    aria-hidden="true"
                />
                {{ layer.name }}
            </li>
            <li class="ml-auto">Peak {{ formatNumber(max) }} a day</li>
        </ul>
    </div>
</template>
