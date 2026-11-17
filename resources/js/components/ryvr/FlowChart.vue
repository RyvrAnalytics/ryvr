<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { computed, ref } from 'vue';
import { formatExact, formatNumber, formatPercent } from '@/lib/format';

export type Flows = {
    nodes: {
        sources: { name: string; visits: number }[];
        pages: { name: string; visits: number }[];
    };
    links: { source: string; page: string; visits: number }[];
};

const props = defineProps<{ flows: Flows }>();
const emit = defineEmits<{ filter: [dimension: string, value: string] }>();

const HEIGHT = 300;
const GAP = 10;
const BAR = 6;

const container = ref<HTMLElement | null>(null);
const width = ref(800);
const focus = ref<{ side: 'source' | 'page'; name: string } | null>(null);

useResizeObserver(container, (entries) => {
    width.value = Math.max(320, entries[0].contentRect.width);
});

// "Other" groups always sit at the bottom of their column.
const orderNodes = <T extends { name: string }>(nodes: T[]) =>
    [...nodes].sort(
        (a, b) =>
            Number(a.name.startsWith('Other')) -
            Number(b.name.startsWith('Other')),
    );

const total = computed(() =>
    props.flows.links.reduce((a, l) => a + l.visits, 0),
);

function layout(nodes: { name: string; visits: number }[]) {
    const ordered = orderNodes(nodes);
    const usable = HEIGHT - GAP * Math.max(0, ordered.length - 1);
    let y = 0;
    return ordered.map((n) => {
        const h = Math.max(2, (n.visits / Math.max(1, total.value)) * usable);
        const node = { ...n, y, h };
        y += h + GAP;
        return node;
    });
}

const sources = computed(() => layout(props.flows.nodes.sources));
const pages = computed(() => layout(props.flows.nodes.pages));
// Narrow screens give the ribbons more room by shortening the label columns.
const labelW = computed(() => (width.value < 560 ? 104 : 150));
const leftX = computed(() => labelW.value);
const rightX = computed(() => width.value - labelW.value);

const ribbons = computed(() => {
    const sOffset: Record<string, number> = {};
    const pOffset: Record<string, number> = {};
    const sIndex = Object.fromEntries(sources.value.map((n) => [n.name, n]));
    const pIndex = Object.fromEntries(pages.value.map((n) => [n.name, n]));
    const usable = HEIGHT - GAP * Math.max(0, sources.value.length - 1);
    const usableP = HEIGHT - GAP * Math.max(0, pages.value.length - 1);

    // Draw in node order so ribbons do not cross more than they must.
    const ordered = [...props.flows.links].sort(
        (a, b) =>
            sources.value.findIndex((n) => n.name === a.source) -
                sources.value.findIndex((n) => n.name === b.source) ||
            pages.value.findIndex((n) => n.name === a.page) -
                pages.value.findIndex((n) => n.name === b.page),
    );

    return ordered.map((link) => {
        const s = sIndex[link.source];
        const p = pIndex[link.page];
        const hs = (link.visits / Math.max(1, total.value)) * usable;
        const hp = (link.visits / Math.max(1, total.value)) * usableP;
        const y0 = s.y + (sOffset[link.source] ?? 0);
        const y1 = p.y + (pOffset[link.page] ?? 0);
        sOffset[link.source] = (sOffset[link.source] ?? 0) + hs;
        pOffset[link.page] = (pOffset[link.page] ?? 0) + hp;
        const x0 = leftX.value + BAR;
        const x1 = rightX.value;
        const mx = (x0 + x1) / 2;
        const d = `M${x0},${y0} C${mx},${y0} ${mx},${y1} ${x1},${y1} L${x1},${y1 + hp} C${mx},${y1 + hp} ${mx},${y0 + hs} ${x0},${y0 + hs} Z`;
        return { ...link, d };
    });
});

function active(link: { source: string; page: string }) {
    if (!focus.value) return true;
    return focus.value.side === 'source'
        ? link.source === focus.value.name
        : link.page === focus.value.name;
}

const focusSummary = computed(() => {
    if (!focus.value) return null;
    const links = props.flows.links.filter(active);
    const visits = links.reduce((a, l) => a + l.visits, 0);
    const top = [...links].sort((a, b) => b.visits - a.visits)[0];
    if (!top) return null;
    return focus.value.side === 'source'
        ? `${formatExact(visits)} visits from ${focus.value.name}. Most land on ${top.page} (${formatPercent((top.visits / visits) * 100, 0)}).`
        : `${formatExact(visits)} visits start on ${focus.value.name}. Most come from ${top.source} (${formatPercent((top.visits / visits) * 100, 0)}).`;
});

function pick(side: 'source' | 'page', name: string) {
    if (name.startsWith('Other')) return;
    emit('filter', side === 'source' ? 'channel' : 'page', name);
}
</script>

<template>
    <div ref="container" class="relative">
        <svg
            :width="width"
            :height="HEIGHT + 8"
            class="block overflow-visible"
            role="img"
            :aria-label="`How ${formatExact(total)} visits arrived: from ${flows.nodes.sources.length} channels to ${flows.nodes.pages.length} landing pages.`"
        >
            <g>
                <path
                    v-for="(r, i) in ribbons"
                    :key="i"
                    :d="r.d"
                    class="transition-opacity duration-150"
                    :class="active(r) ? 'fill-river/25' : 'fill-river/5'"
                >
                    <title>
                        {{ r.source }} to {{ r.page }}:
                        {{ formatExact(r.visits) }} visits
                    </title>
                </path>
            </g>

            <g v-for="n in sources" :key="`s-${n.name}`">
                <rect
                    :x="leftX"
                    :y="n.y"
                    :width="BAR"
                    :height="n.h"
                    rx="2"
                    class="fill-river"
                />
                <foreignObject
                    :x="0"
                    :y="n.y + n.h / 2 - 11"
                    :width="labelW - 10"
                    height="22"
                >
                    <button
                        type="button"
                        class="flex w-full items-baseline justify-end gap-2 text-right text-sm leading-[22px]"
                        :class="
                            n.name.startsWith('Other')
                                ? 'cursor-default text-muted-foreground'
                                : 'hover:text-river'
                        "
                        @mouseenter="focus = { side: 'source', name: n.name }"
                        @mouseleave="focus = null"
                        @focus="focus = { side: 'source', name: n.name }"
                        @blur="focus = null"
                        @click="pick('source', n.name)"
                    >
                        <span class="truncate">{{ n.name }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            formatNumber(n.visits)
                        }}</span>
                    </button>
                </foreignObject>
            </g>

            <g v-for="n in pages" :key="`p-${n.name}`">
                <rect
                    :x="rightX"
                    :y="n.y"
                    :width="BAR"
                    :height="n.h"
                    rx="2"
                    class="fill-foreground/70"
                />
                <foreignObject
                    :x="rightX + BAR + 10"
                    :y="n.y + n.h / 2 - 11"
                    :width="labelW - 16"
                    height="22"
                >
                    <button
                        type="button"
                        class="flex w-full items-baseline gap-2 text-left text-sm leading-[22px]"
                        :class="
                            n.name.startsWith('Other')
                                ? 'cursor-default text-muted-foreground'
                                : 'hover:text-river'
                        "
                        @mouseenter="focus = { side: 'page', name: n.name }"
                        @mouseleave="focus = null"
                        @focus="focus = { side: 'page', name: n.name }"
                        @blur="focus = null"
                        @click="pick('page', n.name)"
                    >
                        <span class="truncate">{{ n.name }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            formatNumber(n.visits)
                        }}</span>
                    </button>
                </foreignObject>
            </g>
        </svg>
        <p
            class="mt-3 min-h-5 text-sm text-muted-foreground"
            aria-live="polite"
        >
            {{
                focusSummary ??
                'Point at a channel or a page to follow its visits. Click to filter the dashboard.'
            }}
        </p>
    </div>
</template>
