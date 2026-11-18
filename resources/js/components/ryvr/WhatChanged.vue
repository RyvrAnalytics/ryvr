<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DimensionIcon from '@/components/ryvr/DimensionIcon.vue';
import SiteIcon from '@/components/ryvr/SiteIcon.vue';
import { countryName, formatExact, formatNumber } from '@/lib/format';

export type Finding = {
    kind: 'rising' | 'falling' | 'new' | 'spike' | 'silent';
    dimension?: string | null;
    value?: string | null;
    site?: { id: string; name: string; domain?: string };
    current: number;
    previous: number;
    change: number | null;
    metric: string;
};

defineProps<{
    findings: Finding[];
    siteHref?: (id: string) => string;
    emptyText?: string;
}>();
const emit = defineEmits<{ filter: [dimension: string, value: string] }>();

const FILTERABLE = [
    'referrer',
    'page',
    'channel',
    'utm_campaign',
    'country',
    'event',
];

const NOUN: Record<string, string> = {
    visitors: 'visitors',
    pageviews: 'views',
    events: 'times',
};

function subject(f: Finding): string {
    if (f.site) return f.site.name;
    if (!f.dimension) return 'Visitors';
    if (f.dimension === 'country') return countryName(f.value ?? '');
    if (f.dimension === 'utm_campaign') return `Campaign ${f.value}`;
    if (f.dimension === 'channel') return `${f.value} traffic`;
    if (f.dimension === 'event') return `“${f.value}” events`;
    return f.value ?? '';
}

function badge(f: Finding): string {
    if (f.kind === 'new') return `+${formatNumber(f.current)}`;
    if (f.kind === 'silent') return 'Quiet';
    if (f.kind === 'spike')
        return `${(f.current / Math.max(1, f.previous)).toFixed(1)}×`;
    const c = Math.round(f.change ?? 0);
    return `${c > 0 ? '+' : '−'}${Math.abs(c)}%`;
}

function sentence(f: Finding): string {
    const noun = NOUN[f.metric] ?? f.metric;
    if (f.kind === 'silent')
        return `had ${formatExact(f.previous)} visitors last period and none now. Check that its tracking code is still installed.`;
    if (f.kind === 'spike')
        return `was the busiest day, with ${formatExact(f.current)} visitors against a typical ${formatExact(f.previous)}.`;
    if (f.kind === 'new') {
        if (f.dimension === 'referrer')
            return `sent ${formatExact(f.current)} visitors, compared with 0 in the previous period.`;
        if (f.dimension === 'event')
            return `were recorded ${formatExact(f.current)} times, compared with 0 in the previous period.`;
        return `had ${formatExact(f.current)} ${noun}, compared with 0 in the previous period.`;
    }
    const direction = f.kind === 'rising' ? 'up' : 'down';
    if (f.dimension === 'referrer')
        return `sent ${formatExact(f.current)} visitors, ${direction} from ${formatExact(f.previous)}.`;
    return `${direction} to ${formatNumber(f.current)} ${noun}, from ${formatNumber(f.previous)}.`;
}

function tone(f: Finding): string {
    if (f.kind === 'new') return 'text-river';
    if (f.kind === 'falling' || f.kind === 'silent') return 'text-down';
    return 'text-up';
}

function spikeDay(value: string | null | undefined): string {
    if (!value) return '';
    const [y, m, d] = value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en', {
        weekday: 'long',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    });
}
</script>

<template>
    <section class="panel flex flex-col" aria-labelledby="what-changed">
        <h2 id="what-changed" class="text-sm text-muted-foreground">
            What changed
        </h2>
        <ol v-if="findings.length" class="mt-3 flex-1 divide-y divide-border">
            <li
                v-for="(f, i) in findings"
                :key="i"
                class="grid grid-cols-[3.5rem_1fr] gap-3 py-3 first:pt-1"
            >
                <span
                    class="text-right text-lg leading-6 font-light"
                    :class="tone(f)"
                    :title="
                        f.kind === 'new'
                            ? `${formatExact(f.current)} more ${f.metric === 'events' ? 'events' : (NOUN[f.metric] ?? f.metric)} than in the previous period`
                            : undefined
                    "
                    >{{ badge(f) }}</span
                >
                <p class="text-sm leading-6">
                    <SiteIcon
                        v-if="f.site && f.site.domain"
                        :domain="f.site.domain"
                        :size="14"
                        class="mr-1.5 inline-block align-[-2px]"
                    />
                    <DimensionIcon
                        v-else-if="
                            f.dimension &&
                            f.value &&
                            ['referrer', 'country', 'channel'].includes(
                                f.dimension,
                            )
                        "
                        :dimension="f.dimension"
                        :value="f.value"
                        class="mr-1.5 inline-block align-[-2px]"
                    />
                    <Link
                        v-if="f.site && siteHref"
                        :href="siteHref(f.site.id)"
                        class="font-medium hover:underline hover:underline-offset-4"
                        >{{ subject(f) }}</Link
                    >
                    <button
                        v-else-if="
                            f.dimension &&
                            f.value &&
                            FILTERABLE.includes(f.dimension)
                        "
                        type="button"
                        class="text-left font-medium break-all hover:underline hover:underline-offset-4"
                        :title="`Show only ${subject(f)}`"
                        @click="emit('filter', f.dimension, f.value)"
                    >
                        {{ subject(f) }}
                    </button>
                    <span v-else-if="f.kind === 'spike'" class="font-medium">{{
                        spikeDay(f.value)
                    }}</span>
                    <span v-else class="font-medium">{{ subject(f) }}</span>
                    {{ ' '
                    }}<span class="text-muted-foreground">{{
                        sentence(f)
                    }}</span>
                </p>
            </li>
        </ol>
        <p v-else class="mt-3 text-sm text-muted-foreground">
            {{
                emptyText ??
                'Nothing out of the ordinary compared with the previous period.'
            }}
        </p>
    </section>
</template>
