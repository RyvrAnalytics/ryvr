<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PeriodPicker from '@/components/ryvr/PeriodPicker.vue';
import Sparkline from '@/components/ryvr/Sparkline.vue';
import { queryParams } from '@/composables/useAnalyticsQuery';
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
    return {
        ...t,
        // Rates are recomputed from the underlying counts, never averaged across sites.
        bounce_rate: t.visits ? (t.bounces / t.visits) * 100 : 0,
        avg_duration: t.visits ? t.duration / t.visits : 0,
    };
});

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

function siteHref(site: Site) {
    const params = new URLSearchParams(
        queryParams(props.query, { filters: {} }),
    );
    return `/sites/${site.id}?${params}`;
}

const ariaSort = (key: SortKey) =>
    sortKey.value === key
        ? sortDesc.value
            ? 'descending'
            : 'ascending'
        : 'none';

let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => router.reload({ only: ['sites'] }), 60_000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="Sites" />

    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-3xl font-light tracking-tight">Sites</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                <template v-if="totals.live"
                    >{{ formatExact(totals.live) }} visitors on your sites right
                    now</template
                >
                <template v-else
                    >{{ sites.length }}
                    {{ sites.length === 1 ? 'site' : 'sites' }}</template
                >
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <PeriodPicker
                :period="query.period"
                :from="query.from"
                :to="query.to"
                @change="(period, from, to) => visit({ period, from, to })"
            />
            <Link
                v-if="canCreate"
                href="/sites/new"
                class="rounded-md bg-foreground px-3 py-1.5 text-sm text-background hover:opacity-90"
                >Add site</Link
            >
        </div>
    </div>

    <template v-if="sites.length || group">
        <dl class="mb-8 grid grid-cols-2 gap-y-6 sm:grid-cols-4">
            <div>
                <dt class="text-sm text-muted-foreground">
                    Visitors, sum of sites
                </dt>
                <dd class="mt-1 text-4xl font-light">
                    {{ formatNumber(totals.visitors) }}
                </dd>
                <dd
                    class="mt-1 text-sm"
                    :class="
                        (change(totals.visitors, totals.previous) ?? 0) >= 0
                            ? 'text-up'
                            : 'text-down'
                    "
                >
                    {{ formatChange(change(totals.visitors, totals.previous)) }}
                    <span class="text-muted-foreground">vs previous</span>
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Pageviews</dt>
                <dd class="mt-1 text-4xl font-light">
                    {{ formatNumber(totals.pageviews) }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Bounce rate</dt>
                <dd class="mt-1 text-4xl font-light">
                    {{ formatPercent(totals.bounce_rate, 0) }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Visit duration</dt>
                <dd class="mt-1 text-4xl font-light">
                    {{ formatDuration(Math.round(totals.avg_duration)) }}
                </dd>
            </div>
        </dl>

        <div class="mb-3 flex flex-wrap items-center gap-4">
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
                    All sites
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
                class="ml-auto w-48 rounded-md border border-input px-2.5 py-1 text-sm"
            />
        </div>

        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full min-w-[720px] text-sm">
                <thead
                    class="bg-surface text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th
                            class="px-4 py-2.5 font-normal"
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
                        <th class="px-4 py-2.5 font-normal">Trend</th>
                        <th
                            class="px-4 py-2.5 text-right font-normal"
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
                            class="px-4 py-2.5 text-right font-normal"
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
                            class="px-4 py-2.5 text-right font-normal"
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
                            class="px-4 py-2.5 text-right font-normal"
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
                            class="px-4 py-2.5 text-right font-normal"
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
                        class="group border-t border-border hover:bg-surface"
                    >
                        <td class="px-4 py-3">
                            <Link :href="siteHref(site)" class="block">
                                <span class="flex items-center gap-2">
                                    <span
                                        class="font-medium group-hover:underline group-hover:underline-offset-4"
                                        >{{ site.name }}</span
                                    >
                                    <span
                                        v-if="site.live"
                                        class="inline-flex items-center gap-1 text-xs text-up"
                                        :title="`${site.live} visitors in the last 5 minutes`"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-up"
                                            aria-hidden="true"
                                        />{{ site.live }}
                                    </span>
                                    <span
                                        v-if="site.environment !== 'production'"
                                        class="text-xs text-muted-foreground"
                                        >{{ site.environment }}</span
                                    >
                                </span>
                                <span class="text-xs text-muted-foreground">{{
                                    site.domain
                                }}</span>
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <Sparkline :values="site.sparkline" />
                        </td>
                        <td class="px-4 py-3 text-right text-base">
                            {{ formatNumber(site.visitors) }}
                        </td>
                        <td
                            class="px-4 py-3 text-right"
                            :class="
                                (change(
                                    site.visitors,
                                    site.previous_visitors,
                                ) ?? 1) >= 0
                                    ? 'text-up'
                                    : 'text-down'
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
                        <td class="px-4 py-3 text-right">
                            {{ formatNumber(site.pageviews) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            {{
                                site.visits
                                    ? formatPercent(site.bounce_rate, 0)
                                    : ''
                            }}
                        </td>
                        <td class="px-4 py-3 text-right">
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
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No sites match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-muted-foreground">
            Visitors are counted per site, so the total is a sum of sites: one
            person who visits two of your sites counts twice.
        </p>
    </template>

    <div v-else class="max-w-lg rounded-xl bg-surface p-8">
        <h2 class="text-lg">Add your first site</h2>
        <p class="mt-2 text-sm text-muted-foreground">
            You will get a one-line script to paste into your site. Visits show
            up here within a minute.
        </p>
        <Link
            v-if="canCreate"
            href="/sites/new"
            class="mt-5 inline-block rounded-md bg-foreground px-3 py-1.5 text-sm text-background"
            >Add site</Link
        >
        <p v-else class="mt-4 text-sm">
            You have not been given access to any sites yet. Ask an admin of
            this organization.
        </p>
    </div>
</template>
