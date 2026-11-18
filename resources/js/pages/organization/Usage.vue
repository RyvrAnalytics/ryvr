<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import OrgNav from '@/components/ryvr/OrgNav.vue';

type Counts = {
    accepted: number;
    filtered: number;
    shed: number;
    bytes: number;
};
const props = defineProps<{
    usage: {
        timezone: string;
        this_month: string;
        last_month: string;
        sites: {
            id: string;
            name: string;
            domain: string;
            this_month: Counts;
            last_month: Counts;
        }[];
        totals: { this_month: Counts; last_month: Counts };
        daily: (Counts & { day: string })[];
    };
}>();
const number = (value: number) => value.toLocaleString('en');
const peak = computed(() =>
    Math.max(1, ...props.usage.daily.map((day) => day.accepted)),
);
</script>

<template>
    <Head title="Usage" />
    <OrgNav />
    <div class="mb-5 flex items-baseline justify-between gap-3">
        <h2 class="text-xl font-medium">Usage</h2>
        <span class="text-xs text-muted-foreground"
            >{{ usage.this_month }} · {{ usage.timezone }}</span
        >
    </div>
    <dl
        class="grid grid-cols-2 gap-4 rounded-lg border border-border p-5 lg:grid-cols-4"
    >
        <div>
            <dt class="text-xs text-muted-foreground">Events this month</dt>
            <dd class="mt-1 text-3xl tabular-nums">
                {{ number(usage.totals.this_month.accepted) }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">
                Last month · {{ usage.last_month }}
            </dt>
            <dd class="mt-1 text-3xl text-muted-foreground tabular-nums">
                {{ number(usage.totals.last_month.accepted) }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Filtered this month</dt>
            <dd class="mt-1 text-3xl tabular-nums">
                {{ number(usage.totals.this_month.filtered) }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Shed this month</dt>
            <dd class="mt-1 text-3xl tabular-nums">
                {{ number(usage.totals.this_month.shed) }}
            </dd>
        </div>
    </dl>
    <section
        class="mt-6 rounded-lg border border-border p-5"
        aria-labelledby="daily-usage"
    >
        <div class="mb-4 flex items-baseline justify-between gap-2">
            <h3 id="daily-usage" class="text-sm font-medium">Daily events</h3>
            <span class="text-xs text-muted-foreground"
                >0 to {{ number(peak) }}</span
            >
        </div>
        <div
            v-if="usage.totals.this_month.accepted"
            class="flex h-32 items-end gap-1 border-b border-border"
            aria-label="Accepted events by day"
        >
            <div
                v-for="day in usage.daily"
                :key="day.day"
                class="flex h-full min-w-0 flex-1 items-end"
            >
                <div
                    tabindex="0"
                    role="img"
                    class="w-full rounded-t-sm bg-river focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="{
                        'border border-dashed border-foreground bg-river-soft':
                            day.day === usage.daily.at(-1)?.day &&
                            day.accepted > 0,
                    }"
                    :style="{
                        height: `${(day.accepted / peak) * 100}%`,
                        minHeight: day.accepted ? '2px' : '0',
                    }"
                    :title="`${day.day}: ${number(day.accepted)} events`"
                    :aria-label="`${day.day}: ${number(day.accepted)} events`"
                />
            </div>
        </div>
        <p
            v-else
            class="flex h-32 items-center justify-center text-sm text-muted-foreground"
        >
            No accepted events this month.
        </p>
        <div class="mt-2 flex justify-between text-xs text-muted-foreground">
            <span>{{ usage.daily[0]?.day }}</span
            ><span>{{ usage.daily.at(-1)?.day }}</span>
        </div>
    </section>
    <section class="mt-6" aria-labelledby="site-usage">
        <h3 id="site-usage" class="mb-3 text-sm font-medium">Events by site</h3>
        <div
            v-if="usage.sites.length"
            class="overflow-x-auto rounded-lg border border-border"
            tabindex="0"
            aria-label="Site usage, scroll for all columns"
        >
            <table class="w-full text-sm">
                <thead class="bg-surface text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left font-normal">
                            Site
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-normal"
                        >
                            This month
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-normal"
                        >
                            Last month
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-normal"
                        >
                            Filtered
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-normal"
                        >
                            Shed
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="site in usage.sites" :key="site.id">
                        <th scope="row" class="px-4 py-3 text-left font-normal">
                            <span class="block">{{ site.name }}</span
                            ><span class="text-xs text-muted-foreground">{{
                                site.domain
                            }}</span>
                        </th>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ number(site.this_month.accepted) }}
                        </td>
                        <td
                            class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                        >
                            {{ number(site.last_month.accepted) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ number(site.this_month.filtered) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ number(site.this_month.shed) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-border font-medium">
                    <tr>
                        <th scope="row" class="px-4 py-3 text-left">Total</th>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ number(usage.totals.this_month.accepted) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ number(usage.totals.last_month.accepted) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ number(usage.totals.this_month.filtered) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ number(usage.totals.this_month.shed) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p
            v-else
            class="rounded-lg border border-border p-5 text-sm text-muted-foreground"
        >
            No sites yet.
        </p>
    </section>
</template>
