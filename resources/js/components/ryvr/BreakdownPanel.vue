<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { DIMENSION_LABELS, queryParams } from '@/composables/useAnalyticsQuery';
import type { QueryState } from '@/composables/useAnalyticsQuery';
import DimensionIcon from '@/components/ryvr/DimensionIcon.vue';
import {
    countryName,
    formatExact,
    formatNumber,
    formatPercent,
} from '@/lib/format';

export type BreakdownRow = {
    value: string;
    visitors: number;
    visits: number;
    pageviews: number;
    events?: number;
    bounce_rate?: number;
};

const props = defineProps<{
    siteId: string;
    tabs: string[];
    initial: BreakdownRow[];
    query: QueryState;
    totalVisitors: number;
    title?: string;
}>();
const emit = defineEmits<{ filter: [dimension: string, value: string] }>();

const active = ref(props.tabs[0]);
const rows = ref<BreakdownRow[]>(props.initial);
const loading = ref(false);
const expanded = ref(false);
const allRows = ref<BreakdownRow[]>([]);
const search = ref('');

// New page props (period or filter changed): reset to the first tab's fresh rows.
watch(
    () => props.initial,
    (value) => {
        if (active.value === props.tabs[0]) rows.value = value;
        else void load(active.value);
    },
);

const metric = computed<'pageviews' | 'visitors' | 'visits'>(() =>
    active.value === 'page'
        ? 'pageviews'
        : active.value === 'entry_page' || active.value === 'exit_page'
          ? 'visits'
          : 'visitors',
);
const metricLabel = computed(
    () =>
        ({ pageviews: 'Views', visitors: 'Visitors', visits: 'Visits' })[
            metric.value
        ],
);
const max = computed(() =>
    Math.max(1, ...rows.value.map((r) => r[metric.value])),
);
const filteredAll = computed(() => {
    const term = search.value.trim().toLowerCase();
    return term
        ? allRows.value.filter((r) =>
              label(r.value).toLowerCase().includes(term),
          )
        : allRows.value;
});
const allMax = computed(() =>
    Math.max(1, ...allRows.value.map((r) => r[metric.value])),
);

async function fetchRows(
    dimension: string,
    limit: number,
): Promise<BreakdownRow[]> {
    const params = new URLSearchParams({
        ...queryParams(props.query),
        dimension,
        limit: String(limit),
    });
    const response = await fetch(`/sites/${props.siteId}/breakdown?${params}`, {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return [];
    return (await response.json()).rows as BreakdownRow[];
}

async function load(dimension: string) {
    active.value = dimension;
    loading.value = true;
    rows.value = await fetchRows(dimension, 10);
    loading.value = false;
}

async function showAll() {
    expanded.value = true;
    search.value = '';
    allRows.value = await fetchRows(active.value, 200);
}

function label(value: string): string {
    if (active.value === 'country') return countryName(value);
    if (active.value === 'referrer') return value || 'Direct or unknown';
    return value || '(not set)';
}

function share(row: BreakdownRow): string {
    return props.totalVisitors && metric.value !== 'pageviews'
        ? formatPercent((row.visitors / props.totalVisitors) * 100, 0)
        : '';
}

function pick(row: BreakdownRow) {
    if (active.value === 'referrer' && !row.value) return;
    emit('filter', active.value, row.value);
    expanded.value = false;
}
</script>

<template>
    <section class="panel flex min-h-[25rem] flex-col">
        <h2 v-if="title" class="mb-2 text-sm text-muted-foreground">
            {{ title }}
        </h2>
        <div class="mb-3 flex items-baseline justify-between gap-3">
            <div class="flex flex-wrap gap-x-4 gap-y-1" role="tablist">
                <button
                    v-for="tab in tabs"
                    :key="tab"
                    type="button"
                    role="tab"
                    :aria-selected="active === tab"
                    class="border-b pb-1 text-sm transition-colors"
                    :class="
                        active === tab
                            ? 'border-foreground text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    @click="load(tab)"
                >
                    {{ DIMENSION_LABELS[tab] ?? tab }}
                </button>
            </div>
            <span class="text-xs text-muted-foreground">{{ metricLabel }}</span>
        </div>

        <ul
            class="flex-1 space-y-0.5"
            :class="{ 'opacity-50': loading }"
            :aria-busy="loading"
        >
            <li v-for="(row, i) in rows" :key="row.value">
                <button
                    type="button"
                    class="group flex w-full items-baseline gap-2 py-1.5 text-left text-sm"
                    :title="`Show only ${label(row.value)}`"
                    @click="pick(row)"
                >
                    <DimensionIcon
                        :dimension="active"
                        :value="row.value"
                        class="self-center"
                    />
                    <span
                        class="max-w-[70%] min-w-0 truncate group-hover:text-river"
                        :class="i === 0 ? 'font-medium' : ''"
                        >{{ label(row.value) }}</span
                    >
                    <span
                        class="min-w-4 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                        aria-hidden="true"
                    />
                    <span
                        class="w-9 text-right text-xs text-muted-foreground"
                        >{{ share(row) }}</span
                    >
                    <span
                        class="w-12 text-right"
                        :style="{ opacity: 0.55 + 0.45 * (row[metric] / max) }"
                        >{{ formatNumber(row[metric]) }}</span
                    >
                </button>
            </li>
            <li
                v-if="!rows.length && !loading"
                class="px-2 py-8 text-sm text-muted-foreground"
            >
                No data for this period.
            </li>
        </ul>

        <button
            v-if="rows.length >= 10"
            type="button"
            class="mt-2 self-start text-sm text-muted-foreground hover:text-foreground"
            @click="showAll"
        >
            Show all
        </button>

        <Dialog v-model:open="expanded">
            <DialogContent class="max-h-[85vh] max-w-2xl overflow-hidden">
                <DialogHeader>
                    <DialogTitle>{{
                        DIMENSION_LABELS[active] ?? active
                    }}</DialogTitle>
                </DialogHeader>
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search"
                    class="w-full rounded-md border border-input px-3 py-1.5 text-sm"
                    aria-label="Search this list"
                />
                <div class="-mx-2 max-h-[60vh] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="sticky top-0 bg-background text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-2 py-1.5 font-normal">
                                    {{ DIMENSION_LABELS[active] }}
                                </th>
                                <th class="px-2 py-1.5 text-right font-normal">
                                    Visitors
                                </th>
                                <th class="px-2 py-1.5 text-right font-normal">
                                    {{
                                        active.endsWith('page') &&
                                        active !== 'page'
                                            ? 'Visits'
                                            : 'Views'
                                    }}
                                </th>
                                <th
                                    v-if="
                                        active === 'entry_page' ||
                                        active === 'exit_page'
                                    "
                                    class="px-2 py-1.5 text-right font-normal"
                                >
                                    Bounce
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in filteredAll"
                                :key="row.value"
                                class="cursor-pointer border-t border-border hover:bg-surface"
                                @click="pick(row)"
                            >
                                <td class="px-2 py-1.5">
                                    <span class="flex items-center gap-2">
                                        <DimensionIcon
                                            :dimension="active"
                                            :value="row.value"
                                        />
                                        {{ label(row.value) }}
                                    </span>
                                </td>
                                <td class="px-2 py-1.5 text-right">
                                    {{ formatExact(row.visitors) }}
                                </td>
                                <td class="px-2 py-1.5 text-right">
                                    {{
                                        formatExact(
                                            active === 'entry_page' ||
                                                active === 'exit_page'
                                                ? row.visits
                                                : row.pageviews,
                                        )
                                    }}
                                </td>
                                <td
                                    v-if="
                                        active === 'entry_page' ||
                                        active === 'exit_page'
                                    "
                                    class="px-2 py-1.5 text-right"
                                >
                                    {{ formatPercent(row.bounce_rate ?? 0) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="!filteredAll.length"
                        class="px-2 py-6 text-sm text-muted-foreground"
                    >
                        Nothing matches.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>
