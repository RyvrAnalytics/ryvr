<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BreakdownPanel from '@/components/ryvr/BreakdownPanel.vue';
import type { BreakdownRow } from '@/components/ryvr/BreakdownPanel.vue';
import EventsPanel from '@/components/ryvr/EventsPanel.vue';
import FirstVisit from '@/components/ryvr/FirstVisit.vue';
import FlowChart from '@/components/ryvr/FlowChart.vue';
import type { Flows } from '@/components/ryvr/FlowChart.vue';
import PeriodPicker from '@/components/ryvr/PeriodPicker.vue';
import TrendChart from '@/components/ryvr/TrendChart.vue';
import type { SeriesPoint } from '@/components/ryvr/TrendChart.vue';
import WhatChanged from '@/components/ryvr/WhatChanged.vue';
import type { Finding } from '@/components/ryvr/WhatChanged.vue';
import {
    DIMENSION_LABELS,
    PERIODS,
    queryParams,
    useAnalyticsQuery,
} from '@/composables/useAnalyticsQuery';
import type { QueryState } from '@/composables/useAnalyticsQuery';
import {
    change,
    countryName,
    formatChange,
    formatDuration,
    formatExact,
    formatNumber,
    formatPercent,
} from '@/lib/format';

type Summary = {
    visitors: number;
    visits: number;
    pageviews: number;
    bounce_rate: number;
    avg_duration: number;
    views_per_visit: number;
    events: number;
};

const props = defineProps<{
    site: {
        id: string;
        name: string;
        domain: string;
        timezone: string;
        canManage: boolean;
        canExport: boolean;
        canManageGoals: boolean;
    };
    query: QueryState;
    summary: Summary;
    previous: Summary;
    series: SeriesPoint[];
    panels: Record<string, BreakdownRow[]>;
    panelTabs: Record<string, string[]>;
    events: { value: string; visitors: number; events?: number }[];
    goals: {
        id: string;
        name: string;
        type: string;
        value: string;
        visitors: number;
        events: number;
        rate: number;
    }[];
    drops: Record<string, number>;
    live: number;
    currents: Finding[];
    flows: Flows;
    setup: { snippet: string | null } | null;
}>();

const { setPeriod, addFilter, removeFilter, clearFilters } = useAnalyticsQuery(
    () => props.query,
);

type ChartMetric = 'visitors' | 'visits' | 'pageviews';
const chartMetric = ref<ChartMetric>('visitors');

const LABELS: Record<ChartMetric, string> = {
    visitors: 'Visitors',
    visits: 'Visits',
    pageviews: 'Pageviews',
};
const PANEL_TITLES: Record<string, string> = {
    pages: 'Pages',
    sources: 'Sources',
    audience: 'Audience',
};

const lead = computed(() => ({
    value: props.summary[chartMetric.value],
    delta: change(
        props.summary[chartMetric.value],
        props.previous[chartMetric.value],
    ),
}));

const switchable = computed(() =>
    (Object.keys(LABELS) as ChartMetric[]).map((key) => ({
        key,
        label: LABELS[key],
        value: formatNumber(props.summary[key]),
        delta: change(props.summary[key], props.previous[key]),
    })),
);

const stats = computed(() => [
    {
        label: 'Bounce rate',
        value: formatPercent(props.summary.bounce_rate, 0),
        delta: change(props.summary.bounce_rate, props.previous.bounce_rate),
        inverse: true,
    },
    {
        label: 'Visit duration',
        value: formatDuration(props.summary.avg_duration),
        delta: change(props.summary.avg_duration, props.previous.avg_duration),
        inverse: false,
    },
    {
        label: 'Views per visit',
        value: props.summary.views_per_visit.toFixed(1),
        delta: change(
            props.summary.views_per_visit,
            props.previous.views_per_visit,
        ),
        inverse: false,
    },
]);

const periodLabel = computed(() =>
    props.query.period === 'custom'
        ? `${props.query.from} to ${props.query.to}`
        : (
              PERIODS.find((p) => p.value === props.query.period)?.label ?? ''
          ).toLowerCase(),
);
const filters = computed(() => Object.entries(props.query.filters));
const hasData = computed(
    () => props.summary.visits > 0 || props.series.some((p) => p.pageviews > 0),
);

const DROP_LABELS: Record<string, [string, string]> = {
    bot: ['crawler', 'crawlers'],
    ai_agent: ['AI agent', 'AI agents'],
    monitor: ['monitoring probe', 'monitoring probes'],
    automation: ['automated browser', 'automated browsers'],
    rule: ['hit excluded by your rules', 'hits excluded by your rules'],
    hostname: ['hit from another hostname', 'hits from other hostnames'],
    referrer_spam: ['referrer spam hit', 'referrer spam hits'],
};
const dropList = computed(() =>
    Object.entries(props.drops).filter(([, n]) => n > 0),
);
const dropTotal = computed(() => dropList.value.reduce((a, [, n]) => a + n, 0));

function deltaClass(delta: number | null, inverse = false) {
    if (delta === null || Math.round(delta) === 0)
        return 'text-muted-foreground';
    return delta > 0 !== inverse ? 'text-up' : 'text-down';
}

function filterLabel(dimension: string, value: string) {
    const negate = value.startsWith('!');
    const raw = negate ? value.slice(1) : value;
    const shown = dimension === 'country' ? countryName(raw) : raw || '(none)';
    return {
        name: DIMENSION_LABELS[dimension] ?? dimension,
        op: negate ? 'is not' : raw.includes('*') ? 'matches' : 'is',
        value: shown,
    };
}

const exportHref = computed(
    () =>
        `/sites/${props.site.id}/export?${new URLSearchParams(queryParams(props.query))}`,
);

const live = ref(props.live);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(async () => {
        const response = await fetch(`/sites/${props.site.id}/realtime`, {
            headers: { Accept: 'application/json' },
        });
        if (response.ok) live.value = (await response.json()).live;
    }, 30_000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="site.name" />

    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <h1 class="truncate text-4xl font-light tracking-tight">
                {{ site.name }}
            </h1>
            <p
                class="mt-1 flex items-center gap-4 text-sm text-muted-foreground"
            >
                <a
                    :href="`https://${site.domain}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:text-foreground"
                    >{{ site.domain }}</a
                >
                <Link
                    :href="`/sites/${site.id}/now`"
                    class="inline-flex items-center gap-1.5 hover:underline hover:underline-offset-4"
                    :class="live ? 'text-up' : 'hover:text-foreground'"
                    title="See what visitors are doing right now"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="live ? 'bg-up' : 'bg-muted-foreground/40'"
                        aria-hidden="true"
                    />
                    {{ live }} online now
                </Link>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <PeriodPicker
                v-if="!setup"
                :period="query.period"
                :from="query.from"
                :to="query.to"
                @change="setPeriod"
            />
            <a
                v-if="site.canExport && !setup"
                :href="exportHref"
                class="text-sm text-muted-foreground hover:text-foreground"
                >Export</a
            >
            <Link
                :href="`/sites/${site.id}/settings`"
                class="text-sm text-muted-foreground hover:text-foreground"
                >Settings</Link
            >
        </div>
    </header>

    <FirstVisit
        v-if="setup"
        :site-id="site.id"
        :domain="site.domain"
        :snippet="setup.snippet"
    />

    <template v-else>
        <div
            v-if="filters.length"
            class="mb-4 flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground">Only</span>
            <span
                v-for="[dimension, value] in filters"
                :key="dimension"
                class="inline-flex items-center gap-1.5 rounded-lg bg-card py-1 pr-1 pl-2.5"
            >
                <span class="text-muted-foreground"
                    >{{ filterLabel(dimension, value).name }}
                    {{ filterLabel(dimension, value).op }}</span
                >
                <span>{{ filterLabel(dimension, value).value }}</span>
                <button
                    type="button"
                    class="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    :aria-label="`Remove ${filterLabel(dimension, value).name} filter`"
                    @click="removeFilter(dimension)"
                >
                    <X class="size-3.5" />
                </button>
            </span>
            <button
                v-if="filters.length > 1"
                type="button"
                class="text-muted-foreground hover:text-foreground hover:underline hover:underline-offset-4"
                @click="clearFilters"
            >
                Clear all
            </button>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_21rem]">
            <section class="panel" aria-label="Traffic">
                <div
                    class="flex flex-wrap items-start justify-between gap-x-10 gap-y-5"
                >
                    <div>
                        <p class="text-sm text-muted-foreground">
                            {{ LABELS[chartMetric] }}, {{ periodLabel }}
                        </p>
                        <p
                            class="mt-1 text-6xl leading-none font-light tracking-tight"
                            :title="formatExact(lead.value)"
                        >
                            {{ formatNumber(lead.value) }}
                        </p>
                        <p class="mt-2 text-sm" :class="deltaClass(lead.delta)">
                            {{ formatChange(lead.delta) }}
                            <span class="text-muted-foreground"
                                >on the previous period</span
                            >
                        </p>
                    </div>

                    <div
                        class="grid w-full max-w-md grid-cols-2 gap-x-8 text-sm sm:w-auto"
                    >
                        <div class="space-y-0.5">
                            <button
                                v-for="m in switchable"
                                :key="m.key"
                                type="button"
                                class="flex w-full items-baseline gap-2 py-0.5 text-left"
                                :aria-pressed="chartMetric === m.key"
                                @click="chartMetric = m.key"
                            >
                                <span
                                    :class="
                                        chartMetric === m.key
                                            ? 'text-foreground underline decoration-river decoration-2 underline-offset-4'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                >
                                    {{ m.label }}
                                </span>
                                <span
                                    class="min-w-3 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                                    aria-hidden="true"
                                />
                                <span>{{ m.value }}</span>
                            </button>
                        </div>
                        <div class="space-y-0.5">
                            <div
                                v-for="s in stats"
                                :key="s.label"
                                class="flex items-baseline gap-2 py-0.5"
                            >
                                <span class="text-muted-foreground">{{
                                    s.label
                                }}</span>
                                <span
                                    class="min-w-3 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                                    aria-hidden="true"
                                />
                                <span
                                    :title="
                                        formatChange(s.delta) +
                                        ' on the previous period'
                                    "
                                >
                                    {{ s.value }}
                                    <span
                                        class="ml-1 text-xs"
                                        :class="deltaClass(s.delta, s.inverse)"
                                        >{{
                                            s.delta === null
                                                ? ''
                                                : formatChange(s.delta)
                                        }}</span
                                    >
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <TrendChart
                        v-if="hasData"
                        :series="series"
                        :metric="chartMetric"
                        :metric-label="LABELS[chartMetric]"
                        :interval="query.interval"
                    />
                    <div
                        v-else
                        class="flex h-[240px] flex-col items-start justify-center gap-2 text-sm"
                    >
                        <p class="text-base">No visits in this period.</p>
                        <p class="text-muted-foreground">
                            If you just added this site, check that the tracking
                            code is on your pages.
                            <Link
                                v-if="site.canManage"
                                :href="`/sites/${site.id}/settings`"
                                class="text-foreground underline underline-offset-4"
                                >See the tracking code</Link
                            >
                        </p>
                    </div>
                </div>
                <p
                    class="mt-2 flex items-center gap-4 text-xs text-muted-foreground"
                >
                    <span class="inline-flex items-center gap-1.5"
                        ><span
                            class="w-4 border-t-2 border-river"
                            aria-hidden="true"
                        />This period</span
                    >
                    <span class="inline-flex items-center gap-1.5"
                        ><span
                            class="w-4 border-t border-dashed border-muted-foreground"
                            aria-hidden="true"
                        />Previous period</span
                    >
                    <span class="ml-auto">{{ query.timezone }}</span>
                </p>
            </section>

            <WhatChanged :findings="currents" @filter="addFilter" />
        </div>

        <section
            v-if="flows.links.length"
            class="panel mt-4"
            aria-labelledby="flow-title"
        >
            <div
                class="mb-4 flex flex-wrap items-baseline justify-between gap-2"
            >
                <h2 id="flow-title" class="text-sm text-muted-foreground">
                    How visitors arrive
                </h2>
                <span class="text-xs text-muted-foreground"
                    >Channel to landing page, by visits</span
                >
            </div>
            <FlowChart :flows="flows" @filter="addFilter" />
        </section>

        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <BreakdownPanel
                v-for="(tabs, panel) in panelTabs"
                :key="panel"
                :title="PANEL_TITLES[panel]"
                :site-id="site.id"
                :tabs="tabs"
                :initial="panels[panel] ?? []"
                :query="query"
                :total-visitors="summary.visitors"
                @filter="addFilter"
            />
        </div>

        <div class="mt-4">
            <EventsPanel
                :site-id="site.id"
                :events="events"
                :goals="goals"
                :query="query"
                :settings-url="`/sites/${site.id}/settings`"
                :can-manage-goals="site.canManageGoals"
                @filter="addFilter"
            />
        </div>

        <p
            v-if="dropTotal"
            class="mt-6 max-w-3xl text-sm text-muted-foreground"
        >
            Kept out of these numbers:
            <template v-for="([reason, count], i) in dropList" :key="reason">
                <span class="text-foreground">{{ formatExact(count) }}</span>
                {{
                    (DROP_LABELS[reason] ?? [reason, reason])[
                        count === 1 ? 0 : 1
                    ]
                }}{{ i < dropList.length - 1 ? ', ' : '.' }}
            </template>
            <Link
                v-if="site.canManage"
                :href="`/sites/${site.id}/settings#exclusions`"
                class="ml-1 text-foreground underline underline-offset-4"
                >Manage exclusions</Link
            >
        </p>
    </template>
</template>
