<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PeriodPicker from '@/components/ryvr/PeriodPicker.vue';
import Sparkline from '@/components/ryvr/Sparkline.vue';
import StreamChart from '@/components/ryvr/StreamChart.vue';
import WhatChanged from '@/components/ryvr/WhatChanged.vue';
import type { Finding } from '@/components/ryvr/WhatChanged.vue';
import { PERIODS, queryParams } from '@/composables/useAnalyticsQuery';
import type { QueryState } from '@/composables/useAnalyticsQuery';
import {
    change,
    formatChange,
    formatDuration,
    formatExact,
    formatNumber,
    formatPercent,
} from '@/lib/format';

type Site = {
    id: string;
    name: string;
    domain: string;
    environment: string;
    live: number;
    visitors: number;
    visits: number;
    pageviews: number;
    bounce_rate: number;
    avg_duration: number;
    previous_visitors: number;
    sparkline: number[];
};

const props = defineProps<{
    query: QueryState;
    groups: { id: string; name: string; count: number }[];
    group: string | null;
    sites: Site[];
    canCreate: boolean;
    currents: Finding[];
    days: string[];
}>();

type SortKey =
    | 'name'
    | 'visitors'
    | 'pageviews'
    | 'bounce_rate'
    | 'avg_duration'
    | 'change';
const sortKey = ref<SortKey>('visitors');
const sortDesc = ref(true);
const search = ref('');

const sorted = computed(() => {
    const term = search.value.trim().toLowerCase();
    const list = props.sites.filter(
        (s) =>
            !term ||
            s.name.toLowerCase().includes(term) ||
            s.domain.includes(term),
    );
    const value = (s: Site) =>
        sortKey.value === 'change'
            ? (change(s.visitors, s.previous_visitors) ?? Infinity)
            : s[sortKey.value];
    return [...list].sort((a, b) => {
        const av = value(a);
        const bv = value(b);
        const cmp =
            typeof av === 'string'
                ? av.localeCompare(bv as string)
                : (av as number) - (bv as number);
        return sortDesc.value ? -cmp : cmp;
    });
});

const totals = computed(() => {
    const t = props.sites.reduce(
        (acc, s) => ({
            visitors: acc.visitors + s.visitors,
            previous: acc.previous + s.previous_visitors,
            pageviews: acc.pageviews + s.pageviews,
            visits: acc.visits + s.visits,
            live: acc.live + s.live,
            bounces: acc.bounces + (s.bounce_rate / 100) * s.visits,
            duration: acc.duration + s.avg_duration * s.visits,
        }),
        {
            visitors: 0,
            previous: 0,
            pageviews: 0,
            visits: 0,
            live: 0,
            bounces: 0,
            duration: 0,
        },
    );
    // Rates are recomputed from the underlying counts, never averaged across sites.
    return {
        ...t,
        bounce_rate: t.visits ? (t.bounces / t.visits) * 100 : 0,
        avg_duration: t.visits ? t.duration / t.visits : 0,
    };
});

const totalDelta = computed(() =>
    change(totals.value.visitors, totals.value.previous),
);
const periodLabel = computed(() =>
    props.query.period === 'custom'
        ? `${props.query.from} to ${props.query.to}`
        : (
              PERIODS.find((p) => p.value === props.query.period)?.label ?? ''
          ).toLowerCase(),
);

function sortBy(key: SortKey) {
    if (sortKey.value === key) sortDesc.value = !sortDesc.value;
    else {
        sortKey.value = key;
        sortDesc.value = key !== 'name';
    }
}

function visit(changes: {
    period?: string;
    from?: string;
    to?: string;
    group?: string | null;
}) {
    const params: Record<string, string> = queryParams(props.query, changes);
    const group = changes.group === undefined ? props.group : changes.group;
    if (group) params.group = group;
    router.get('/sites', params, { preserveScroll: true, preserveState: true });
}

const siteHref = (id: string) =>
    `/sites/${id}?${new URLSearchParams(queryParams(props.query, { filters: {} }))}`;
const ariaSort = (key: SortKey) =>
    sortKey.value === key
        ? sortDesc.value
            ? 'descending'
            : 'ascending'
        : 'none';
const deltaClass = (d: number | null) =>
    d === null || Math.round(d) === 0
        ? 'text-muted-foreground'
        : d > 0
          ? 'text-up'
          : 'text-down';

let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(
        () => router.reload({ only: ['sites', 'rail'] }),
        60_000,
    );
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="All sites" />

    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <h1 class="text-4xl font-light tracking-tight">All sites</h1>
        <PeriodPicker
            :period="query.period"
            :from="query.from"
            :to="query.to"
            @change="(period, from, to) => visit({ period, from, to })"
        />
    </header>

    <template v-if="sites.length || group">
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_21rem]">
            <section class="panel" aria-label="Traffic across sites">
                <div
                    class="flex flex-wrap items-end justify-between gap-x-10 gap-y-4"
                >
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Visitors across {{ sites.length }}
                            {{ sites.length === 1 ? 'site' : 'sites' }},
                            {{ periodLabel }}
                        </p>
                        <p
                            class="mt-1 text-6xl leading-none font-light tracking-tight"
                            :title="formatExact(totals.visitors)"
                        >
                            {{ formatNumber(totals.visitors) }}
                        </p>
                        <p class="mt-2 text-sm" :class="deltaClass(totalDelta)">
                            {{ formatChange(totalDelta) }}
                            <span class="text-muted-foreground"
                                >on the previous period</span
                            >
                        </p>
                    </div>
                    <dl class="grid min-w-56 gap-0.5 text-sm">
                        <div
                            v-for="s in [
                                {
                                    label: 'Online now',
                                    value: formatExact(totals.live),
                                },
                                {
                                    label: 'Pageviews',
                                    value: formatNumber(totals.pageviews),
                                },
                                {
                                    label: 'Bounce rate',
                                    value: formatPercent(totals.bounce_rate, 0),
                                },
                                {
                                    label: 'Visit duration',
                                    value: formatDuration(
                                        Math.round(totals.avg_duration),
                                    ),
                                },
                            ]"
                            :key="s.label"
                            class="flex items-baseline gap-2"
                        >
                            <dt class="text-muted-foreground">{{ s.label }}</dt>
                            <span
                                class="min-w-3 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                                aria-hidden="true"
                            />
                            <dd>{{ s.value }}</dd>
                        </div>
                    </dl>
                </div>
                <div class="mt-6">
                    <StreamChart :sites="sites" :days="days" />
                </div>
            </section>

            <WhatChanged
                :findings="currents"
                :site-href="siteHref"
                empty-text="Every site is tracking along as usual."
            />
        </div>

        <section class="panel mt-4 p-0" aria-label="Sites">
            <div class="flex flex-wrap items-center gap-4 px-5 pt-4">
                <div
                    v-if="groups.length"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                    role="tablist"
                    aria-label="Group"
                >
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="!group"
                        class="border-b pb-0.5"
                        :class="
                            !group
                                ? 'border-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        @click="visit({ group: null })"
                    >
                        Every group
                    </button>
                    <button
                        v-for="g in groups"
                        :key="g.id"
                        type="button"
                        role="tab"
                        :aria-selected="group === g.id"
                        class="border-b pb-0.5"
                        :class="
                            group === g.id
                                ? 'border-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        @click="visit({ group: g.id })"
                    >
                        {{ g.name }}
                        <span class="text-muted-foreground">{{ g.count }}</span>
                    </button>
                </div>
                <input
                    v-if="sites.length > 6"
                    v-model="search"
                    type="search"
                    placeholder="Find a site"
                    aria-label="Find a site"
                    class="ml-auto w-48 rounded-lg border border-input bg-transparent px-2.5 py-1 text-sm"
                />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th
                                class="px-5 py-3 font-normal"
                                :aria-sort="ariaSort('name')"
                            >
                                <button
                                    type="button"
                                    class="hover:text-foreground"
                                    @click="sortBy('name')"
                                >
                                    Site
                                </button>
                            </th>
                            <th class="px-3 py-3 font-normal">
                                Daily visitors
                            </th>
                            <th
                                class="px-3 py-3 text-right font-normal"
                                :aria-sort="ariaSort('visitors')"
                            >
                                <button
                                    type="button"
                                    class="hover:text-foreground"
                                    @click="sortBy('visitors')"
                                >
                                    Visitors
                                </button>
                            </th>
                            <th
                                class="px-3 py-3 text-right font-normal"
                                :aria-sort="ariaSort('change')"
                            >
                                <button
                                    type="button"
                                    class="hover:text-foreground"
                                    @click="sortBy('change')"
                                >
                                    Change
                                </button>
                            </th>
                            <th
                                class="px-3 py-3 text-right font-normal"
                                :aria-sort="ariaSort('pageviews')"
                            >
                                <button
                                    type="button"
                                    class="hover:text-foreground"
                                    @click="sortBy('pageviews')"
                                >
                                    Pageviews
                                </button>
                            </th>
                            <th
                                class="px-3 py-3 text-right font-normal"
                                :aria-sort="ariaSort('bounce_rate')"
                            >
                                <button
                                    type="button"
                                    class="hover:text-foreground"
                                    @click="sortBy('bounce_rate')"
                                >
                                    Bounce
                                </button>
                            </th>
                            <th
                                class="px-5 py-3 text-right font-normal"
                                :aria-sort="ariaSort('avg_duration')"
                            >
                                <button
                                    type="button"
                                    class="hover:text-foreground"
                                    @click="sortBy('avg_duration')"
                                >
                                    Duration
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="site in sorted"
                            :key="site.id"
                            class="group border-t border-border"
                        >
                            <td class="px-5 py-3">
                                <Link :href="siteHref(site.id)" class="block">
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="font-medium group-hover:text-river"
                                            >{{ site.name }}</span
                                        >
                                        <span
                                            v-if="
                                                site.environment !==
                                                'production'
                                            "
                                            class="text-xs text-muted-foreground"
                                            >{{ site.environment }}</span
                                        >
                                    </span>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ site.domain }}</span
                                    >
                                </Link>
                            </td>
                            <td class="px-3 py-3">
                                <Sparkline :values="site.sparkline" />
                            </td>
                            <td class="px-3 py-3 text-right text-base">
                                {{ formatNumber(site.visitors) }}
                            </td>
                            <td
                                class="px-3 py-3 text-right"
                                :class="
                                    deltaClass(
                                        change(
                                            site.visitors,
                                            site.previous_visitors,
                                        ),
                                    )
                                "
                            >
                                {{
                                    site.visitors || site.previous_visitors
                                        ? formatChange(
                                              change(
                                                  site.visitors,
                                                  site.previous_visitors,
                                              ),
                                          )
                                        : ''
                                }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                {{ formatNumber(site.pageviews) }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                {{
                                    site.visits
                                        ? formatPercent(site.bounce_rate, 0)
                                        : ''
                                }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                {{
                                    site.visits
                                        ? formatDuration(site.avg_duration)
                                        : ''
                                }}
                            </td>
                        </tr>
                        <tr v-if="!sorted.length">
                            <td
                                colspan="7"
                                class="px-5 py-10 text-center text-muted-foreground"
                            >
                                No sites match.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <p class="mt-3 text-xs text-muted-foreground">
            Visitors are counted per site, so totals are a sum of sites: one
            person who visits two of your sites counts twice.
        </p>
    </template>

    <section v-else class="panel max-w-lg">
        <h2 class="text-lg">Add your first site</h2>
        <p class="mt-2 text-sm text-muted-foreground">
            You will get one line to paste into your site. Visits show up here
            within a minute.
        </p>
        <Link v-if="canCreate" href="/sites/new" class="btn-primary mt-5"
            >Add site</Link
        >
        <p v-else class="mt-4 text-sm">
            You have not been given access to any sites yet. Ask an admin of
            this organization.
        </p>
    </section>
</template>
