<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BreakdownPanel from '@/components/ryvr/BreakdownPanel.vue';
import type { BreakdownRow } from '@/components/ryvr/BreakdownPanel.vue';
import EventsPanel from '@/components/ryvr/EventsPanel.vue';
import PeriodPicker from '@/components/ryvr/PeriodPicker.vue';
import TrendChart from '@/components/ryvr/TrendChart.vue';
import type { SeriesPoint } from '@/components/ryvr/TrendChart.vue';
import {
    DIMENSION_LABELS,
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
}>();

const { setPeriod, addFilter, removeFilter, clearFilters } = useAnalyticsQuery(
    () => props.query,
);

type ChartMetric = 'visitors' | 'visits' | 'pageviews';
const chartMetric = ref<ChartMetric>('visitors');

const metrics = computed(() => [
    {
        key: 'visitors',
        label: 'Visitors',
        value: formatNumber(props.summary.visitors),
        exact: props.summary.visitors,
        delta: change(props.summary.visitors, props.previous.visitors),
        chart: true,
    },
    {
        key: 'visits',
        label: 'Visits',
        value: formatNumber(props.summary.visits),
        exact: props.summary.visits,
        delta: change(props.summary.visits, props.previous.visits),
        chart: true,
    },
    {
        key: 'pageviews',
        label: 'Pageviews',
        value: formatNumber(props.summary.pageviews),
        exact: props.summary.pageviews,
        delta: change(props.summary.pageviews, props.previous.pageviews),
        chart: true,
    },
    {
        key: 'views_per_visit',
        label: 'Views per visit',
        value: props.summary.views_per_visit.toFixed(1),
        delta: change(
            props.summary.views_per_visit,
            props.previous.views_per_visit,
        ),
        chart: false,
    },
    {
        key: 'bounce_rate',
        label: 'Bounce rate',
        value: formatPercent(props.summary.bounce_rate, 0),
        delta: change(props.summary.bounce_rate, props.previous.bounce_rate),
        chart: false,
        inverse: true,
    },
    {
        key: 'avg_duration',
        label: 'Visit duration',
        value: formatDuration(props.summary.avg_duration),
        delta: change(props.summary.avg_duration, props.previous.avg_duration),
        chart: false,
    },
]);

const chartLabel = computed(
    () =>
        ({ visitors: 'Visitors', visits: 'Visits', pageviews: 'Pageviews' })[
            chartMetric.value
        ],
);
const filters = computed(() => Object.entries(props.query.filters));
const hasData = computed(
    () => props.summary.visits > 0 || props.series.some((p) => p.pageviews > 0),
);

const DROP_LABELS: Record<string, string> = {
    bot: 'crawler',
    ai_agent: 'AI agent',
    monitor: 'monitoring probe',
    automation: 'automated browser',
    rule: 'excluded by your rules',
    hostname: 'from other hostnames',
    referrer_spam: 'referrer spam',
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

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <Link
                href="/sites"
                class="text-sm text-muted-foreground hover:text-foreground"
                >Sites</Link
            >
            <h1 class="mt-1 truncate text-3xl font-light tracking-tight">
                {{ site.name }}
            </h1>
            <p
                class="mt-1 flex items-center gap-3 text-sm text-muted-foreground"
            >
                <a
                    :href="`https://${site.domain}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:text-foreground"
                    >{{ site.domain }}</a
                >
                <span
                    class="inline-flex items-center gap-1.5"
                    :class="live ? 'text-up' : ''"
                    :title="'Visitors in the last 5 minutes'"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="live ? 'bg-up' : 'bg-muted-foreground/40'"
                        aria-hidden="true"
                    />
                    {{ live }} online
                </span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <PeriodPicker
                :period="query.period"
                :from="query.from"
                :to="query.to"
                @change="setPeriod"
            />
            <a
                v-if="site.canExport"
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
    </div>

    <div
        v-if="filters.length"
        class="mb-4 flex flex-wrap items-center gap-2 text-sm"
    >
        <span class="text-muted-foreground">Showing</span>
        <span
            v-for="[dimension, value] in filters"
            :key="dimension"
            class="inline-flex items-center gap-1.5 rounded-md border border-border py-0.5 pr-1 pl-2"
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
            class="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            @click="clearFilters"
        >
            Clear all
        </button>
    </div>

    <section class="mb-6 rounded-xl border border-border" aria-label="Overview">
        <dl
            class="grid grid-cols-2 border-b border-border sm:grid-cols-3 lg:grid-cols-6"
        >
            <component
                :is="metric.chart ? 'button' : 'div'"
                v-for="metric in metrics"
                :key="metric.key"
                :type="metric.chart ? 'button' : undefined"
                class="relative px-5 py-4 text-left"
                :class="[
                    metric.chart ? 'hover:bg-surface' : '',
                    chartMetric === metric.key
                        ? 'after:absolute after:inset-x-5 after:bottom-0 after:h-0.5 after:bg-foreground'
                        : '',
                ]"
                :aria-pressed="
                    metric.chart ? chartMetric === metric.key : undefined
                "
                @click="
                    metric.chart && (chartMetric = metric.key as ChartMetric)
                "
            >
                <dt class="text-sm text-muted-foreground">
                    {{ metric.label }}
                </dt>
                <dd
                    class="mt-1 text-[2rem] leading-tight font-light"
                    :title="
                        metric.exact !== undefined
                            ? formatExact(metric.exact)
                            : undefined
                    "
                >
                    {{ metric.value }}
                </dd>
                <dd
                    class="mt-0.5 text-xs"
                    :class="deltaClass(metric.delta, metric.inverse)"
                >
                    {{ formatChange(metric.delta) }}
                </dd>
            </component>
        </dl>
        <div class="px-5 pt-5 pb-3">
            <TrendChart
                v-if="hasData"
                :series="series"
                :metric="chartMetric"
                :metric-label="chartLabel"
                :interval="query.interval"
            />
            <div
                v-else
                class="flex h-[220px] flex-col items-start justify-center gap-2 text-sm"
            >
                <p class="text-base">No visits in this period.</p>
                <p class="text-muted-foreground">
                    If you just added this site, check that the tracking code is
                    on your pages.
                    <Link
                        v-if="site.canManage"
                        :href="`/sites/${site.id}/settings`"
                        class="text-foreground underline underline-offset-4"
                        >See the tracking code</Link
                    >
                </p>
            </div>
        </div>
        <div
            class="flex items-center gap-4 px-5 pb-4 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5"
                ><span
                    class="h-2.5 w-3 rounded-sm bg-river-soft"
                    aria-hidden="true"
                />{{ chartLabel }}</span
            >
            <span class="inline-flex items-center gap-1.5"
                ><span
                    class="w-3 border-t border-dashed border-muted-foreground"
                    aria-hidden="true"
                />Previous period</span
            >
            <span class="ml-auto">Times in {{ query.timezone }}</span>
        </div>
    </section>

    <div class="grid gap-4 md:grid-cols-2">
        <BreakdownPanel
            v-for="(tabs, panel) in panelTabs"
            :key="panel"
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

    <p v-if="dropTotal" class="mt-6 text-sm text-muted-foreground">
        Not counted in this period:
        <template v-for="([reason, count], i) in dropList" :key="reason">
            <span class="text-foreground">{{ formatExact(count) }}</span>
            {{ DROP_LABELS[reason] ?? reason
            }}{{
                count === 1 ||
                reason === 'rule' ||
                reason === 'hostname' ||
                reason === 'referrer_spam'
                    ? ''
                    : 's'
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
