<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { countryName, formatExact } from '@/lib/format';

type Row = { value: string; visitors: number };
type NowData = {
    online: number;
    minutes: { minute: string; visitors: number; pageviews: number }[];
    pages: Row[];
    sources: Row[];
    countries: Row[];
};

const props = defineProps<{
    site: { id: string; name: string; domain: string; timezone: string };
    now: NowData;
}>();

const data = ref<NowData>(props.now);
const updated = ref(new Date());
const hover = ref<number | null>(null);

const peak = computed(() =>
    Math.max(1, ...data.value.minutes.map((m) => m.visitors)),
);
const lastHalfHour = computed(() =>
    data.value.minutes.reduce((a, m) => a + m.pageviews, 0),
);

const lists = computed(() => [
    {
        title: 'Pages being read',
        hint: 'Last 5 minutes',
        rows: data.value.pages,
        empty: 'No one is reading a page right now.',
        label: (v: string) => v,
    },
    {
        title: 'Arriving from',
        hint: 'Last 30 minutes',
        rows: data.value.sources,
        empty: 'No arrivals in the last 30 minutes.',
        label: (v: string) => v || 'Direct',
    },
    {
        title: 'Where they are',
        hint: 'Last 30 minutes',
        rows: data.value.countries,
        empty: 'No visitors in the last 30 minutes.',
        label: (v: string) => countryName(v),
    },
]);

let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(async () => {
        if (document.hidden) return;
        const response = await fetch(`/sites/${props.site.id}/now`, {
            headers: { Accept: 'application/json' },
        });
        if (response.ok) {
            data.value = await response.json();
            updated.value = new Date();
        }
    }, 8000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="`${site.name}, now`" />

    <header class="mb-6">
        <Link
            :href="`/sites/${site.id}`"
            class="text-sm text-muted-foreground hover:text-foreground"
            >{{ site.name }}</Link
        >
        <h1 class="mt-1 text-4xl font-light tracking-tight">Right now</h1>
    </header>

    <section class="panel" aria-label="Live activity">
        <div class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <span class="relative flex size-2">
                        <span
                            v-if="data.online"
                            class="absolute inline-flex size-full animate-ping rounded-full bg-up/60 motion-reduce:animate-none"
                        />
                        <span
                            class="relative inline-flex size-2 rounded-full"
                            :class="
                                data.online ? 'bg-up' : 'bg-muted-foreground/40'
                            "
                        />
                    </span>
                    Visitors in the last 5 minutes
                </p>
                <p class="mt-1 text-7xl leading-none font-light tracking-tight">
                    {{ formatExact(data.online) }}
                </p>
            </div>
            <p class="text-right text-sm text-muted-foreground">
                {{ formatExact(lastHalfHour) }} pageviews in the last 30 minutes
                <br />
                <span class="text-xs"
                    >Updates every few seconds. Last checked
                    {{
                        updated.toLocaleTimeString('en', {
                            hour: 'numeric',
                            minute: '2-digit',
                            second: '2-digit',
                        })
                    }}</span
                >
            </p>
        </div>

        <div class="mt-8" @mouseleave="hover = null">
            <div
                class="flex h-28 items-end gap-[3px]"
                role="img"
                :aria-label="`Visitors per minute over the last 30 minutes, peaking at ${peak}.`"
            >
                <div
                    v-for="(m, i) in data.minutes"
                    :key="m.minute"
                    class="relative flex-1"
                    @mouseenter="hover = i"
                >
                    <div
                        class="w-full rounded-t-sm transition-[height] duration-500 motion-reduce:transition-none"
                        :class="
                            i === data.minutes.length - 1 || hover === i
                                ? 'bg-river'
                                : 'bg-river-soft'
                        "
                        :style="{
                            height: `${Math.max(2, (m.visitors / peak) * 112)}px`,
                        }"
                    />
                </div>
            </div>
            <div
                class="mt-2 flex justify-between text-[11px] text-muted-foreground"
            >
                <span>30 minutes ago</span>
                <span v-if="hover !== null" class="text-foreground"
                    >{{ data.minutes[hover].minute }}:
                    {{ data.minutes[hover].visitors }} visitors,
                    {{ data.minutes[hover].pageviews }} pageviews</span
                >
                <span>Now</span>
            </div>
        </div>
    </section>

    <div class="mt-4 grid gap-4 md:grid-cols-3">
        <section v-for="list in lists" :key="list.title" class="panel">
            <div class="mb-3 flex items-baseline justify-between">
                <h2 class="text-sm text-muted-foreground">{{ list.title }}</h2>
                <span class="text-xs text-muted-foreground">{{
                    list.hint
                }}</span>
            </div>
            <ul v-if="list.rows.length">
                <li
                    v-for="row in list.rows"
                    :key="row.value"
                    class="flex items-baseline gap-2 py-1.5 text-sm"
                >
                    <span class="max-w-[75%] min-w-0 truncate">{{
                        list.label(row.value)
                    }}</span>
                    <span
                        class="min-w-4 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                        aria-hidden="true"
                    />
                    <span>{{ formatExact(row.visitors) }}</span>
                </li>
            </ul>
            <p v-else class="py-6 text-sm text-muted-foreground">
                {{ list.empty }}
            </p>
        </section>
    </div>
</template>
