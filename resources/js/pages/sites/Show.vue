<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BreakdownPanel from '@/components/ryvr/BreakdownPanel.vue';
import type { BreakdownRow } from '@/components/ryvr/BreakdownPanel.vue';
import DimensionIcon from '@/components/ryvr/DimensionIcon.vue';
import EventsPanel from '@/components/ryvr/EventsPanel.vue';
import FirstVisit from '@/components/ryvr/FirstVisit.vue';
import type { Flows } from '@/types/flows';
import PeriodPicker from '@/components/ryvr/PeriodPicker.vue';
import StreamChart from '@/components/ryvr/StreamChart.vue';
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
    periodIncludesNow,
    useDashboardRefresh,
} from '@/composables/useDashboardRefresh';
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
    channels: {
        name: string;
        visitors: number[];
        visits: number[];
        pageviews: number[];
    }[];
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
const { lastUpdated } = useDashboardRefresh(
    [
        'series',
        'channels',
        'summary',
        'previous',
        'panels',
        'events',
        'goals',
        'drops',
        'currents',
        'flows',
    ],
    () => !props.setup && periodIncludesNow(props.query),
);
const updatedTime = computed(() =>
    lastUpdated.value?.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }),
);

type ChartMetric = 'visitors' | 'visits' | 'pageviews';
const chartMetric = ref<ChartMetric>('visitors');
const channelLayers = computed(() =>
    props.channels.map((c) => ({ name: c.name, values: c[chartMetric.value] })),
);
const buckets = computed(() => props.series.map((p) => p.bucket));
const chartTotals = computed(() =>
    props.series.map((p) => p[chartMetric.value]),
);
const chartGhost = computed(() =>
    props.series.map((p) => p.previous?.[chartMetric.value] ?? 0),
);

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
    quota: [
        'hit refused by organization limits',
        'hits refused by organization limits',
    ],
    shed: [
        'hit refused while the server was overloaded',
        'hits refused while the server was overloaded',
    ],
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
                class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground"
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
                <span v-if="updatedTime && !setup">
                    Updated {{ updatedTime }}
                </span>
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

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_21rem]">
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
                        class="grid w-full max-w-md grid-cols-1 gap-x-8 text-sm min-[480px]:grid-cols-2 sm:w-auto"
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
                    <StreamChart
                        v-if="hasData"
                        :layers="channelLayers"
                        :days="buckets"
                        :interval="query.interval as 'hour' | 'day' | 'month'"
                        :totals="chartTotals"
                        :ghost="chartGhost"
                        :total-label="LABELS[chartMetric]"
                        :other-label="
                            (count: number) => `${count} other channels`
                        "
                        :describe="`${LABELS[chartMetric]} by channel`"
                        :height="240"
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
                <p class="mt-1 text-right text-xs text-muted-foreground">
                    {{ query.timezone }}
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
                class="mb-5 flex flex-wrap items-baseline justify-between gap-2"
            >
                <h2 id="flow-title" class="text-sm text-muted-foreground">
                    Where each channel lands
                </h2>
                <span class="text-xs text-muted-foreground"
                    >Share of each channel's visits by first page</span
                >
            </div>
            <ul class="space-y-4">
                <li
                    v-for="source in flows.nodes.sources"
                    :key="source.name"
                    class="grid items-center gap-x-4 gap-y-1 sm:grid-cols-[9rem_1fr]"
                >
                    <button
                        type="button"
                        class="flex items-baseline justify-between gap-2 text-left text-sm hover:text-river disabled:cursor-default disabled:hover:text-current"
                        :disabled="source.name.startsWith('Other')"
                        @click="addFilter('channel', source.name)"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <DimensionIcon
                                dimension="channel"
                                :value="source.name"
                            />
                            <span class="truncate">{{ source.name }}</span>
                        </span>
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                            >{{ formatNumber(source.visits) }}</span
                        >
                    </button>
                    <div
                        class="flex h-[26px] w-full overflow-hidden rounded-md"
                    >
                        <div
                            v-for="(page, pi) in flows.nodes.pages"
                            :key="page.name"
                            class="relative h-full min-w-0 border-r-2 border-card last:border-r-0"
                            :class="
                                [
                                    'bg-river',
                                    'bg-stream-2',
                                    'bg-stream-3',
                                    'bg-stream-4',
                                    'bg-stream-5',
                                    'bg-muted-foreground/25',
                                    'bg-muted-foreground/15',
                                ][pi] ?? 'bg-muted'
                            "
                            :style="{
                                width: `${((flows.links.find((l) => l.source === source.name && l.page === page.name)?.visits ?? 0) / source.visits) * 100}%`,
                            }"
                            :title="`${source.name} to ${page.name}: ${formatExact(flows.links.find((l) => l.source === source.name && l.page === page.name)?.visits ?? 0)} visits`"
                        >
                            <span
                                v-if="
                                    (flows.links.find(
                                        (l) =>
                                            l.source === source.name &&
                                            l.page === page.name,
                                    )?.visits ?? 0) /
                                        source.visits >
                                    0.12
                                "
                                class="absolute inset-0 truncate px-1.5 text-[11px] leading-[26px]"
                                :class="
                                    pi < 2 ? 'text-white' : 'text-foreground'
                                "
                                >{{ page.name }}</span
                            >
                        </div>
                    </div>
                </li>
            </ul>
            <ul
                class="mt-5 flex flex-wrap gap-x-4 gap-y-1 border-t border-border pt-4 text-xs text-muted-foreground"
            >
                <li
                    v-for="(page, pi) in flows.nodes.pages"
                    :key="page.name"
                    class="inline-flex items-center gap-1.5"
                >
                    <span
                        class="size-2.5 rounded-sm"
                        :class="
                            [
                                'bg-river',
                                'bg-stream-2',
                                'bg-stream-3',
                                'bg-stream-4',
                                'bg-stream-5',
                                'bg-muted-foreground/25',
                                'bg-muted-foreground/15',
                            ][pi] ?? 'bg-muted'
                        "
                        aria-hidden="true"
                    />
                    {{ page.name }}
                </li>
            </ul>
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
