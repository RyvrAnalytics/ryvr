<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { queryParams } from '@/composables/useAnalyticsQuery';
import type { QueryState } from '@/composables/useAnalyticsQuery';
import { formatNumber, formatPercent } from '@/lib/format';

type EventRow = { value: string; visitors: number; events?: number };
type Goal = {
    id: string;
    name: string;
    type: string;
    value: string;
    visitors: number;
    events: number;
    rate: number;
};
type PropRow = { value: string; visitors: number; events: number };

const props = defineProps<{
    siteId: string;
    events: EventRow[];
    goals: Goal[];
    query: QueryState;
    settingsUrl: string;
    canManageGoals: boolean;
}>();
const emit = defineEmits<{ filter: [dimension: string, value: string] }>();

const open = ref<string | null>(null);
const keys = ref<string[]>([]);
const property = ref('');
const propRows = ref<PropRow[]>([]);
const loading = ref(false);

watch([() => props.query, () => props.events], () => {
    if (open.value) void loadProps(open.value, property.value);
});

const maxEvents = computed(() =>
    Math.max(1, ...props.events.map((e) => e.events ?? 0)),
);
const maxProps = computed(() =>
    Math.max(1, ...propRows.value.map((r) => r.events)),
);

async function loadProps(event: string, prop = '') {
    loading.value = true;
    const params = new URLSearchParams({
        ...queryParams(props.query),
        event,
        property: prop,
        limit: '20',
    });
    const response = await fetch(`/sites/${props.siteId}/breakdown?${params}`, {
        headers: { Accept: 'application/json' },
    });
    const data = response.ok
        ? await response.json()
        : { keys: [], property: '', rows: [] };
    keys.value = data.keys;
    property.value = data.property;
    propRows.value = data.rows;
    loading.value = false;
}

function toggle(event: string) {
    if (open.value === event) {
        open.value = null;
        return;
    }
    open.value = event;
    void loadProps(event);
}
</script>

<template>
    <section class="panel">
        <div class="grid gap-8 lg:grid-cols-[3fr_2fr]">
            <div>
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="text-sm">Events</h2>
                    <span class="text-xs text-muted-foreground">Count</span>
                </div>
                <ul v-if="events.length" class="space-y-0.5">
                    <li v-for="event in events" :key="event.value">
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                class="group flex flex-1 items-baseline gap-2 py-1.5 text-left text-sm"
                                :aria-expanded="open === event.value"
                                @click="toggle(event.value)"
                            >
                                <span
                                    class="max-w-[60%] min-w-0 truncate group-hover:text-river"
                                    >{{ event.value }}</span
                                >
                                <span
                                    class="min-w-4 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                                    aria-hidden="true"
                                />
                                <span class="text-xs text-muted-foreground"
                                    >{{
                                        formatNumber(event.visitors)
                                    }}
                                    visitors</span
                                >
                                <span
                                    class="w-12 text-right"
                                    :style="{
                                        opacity:
                                            0.55 +
                                            0.45 *
                                                ((event.events ?? 0) /
                                                    maxEvents),
                                    }"
                                    >{{ formatNumber(event.events ?? 0) }}</span
                                >
                            </button>
                            <button
                                type="button"
                                class="rounded px-1.5 py-1 text-xs text-muted-foreground hover:text-foreground"
                                :title="`Filter by ${event.value}`"
                                @click="emit('filter', 'event', event.value)"
                            >
                                Filter
                            </button>
                        </div>

                        <div
                            v-if="open === event.value"
                            class="mb-2 ml-2 border-l border-border pt-2 pl-3"
                        >
                            <div
                                v-if="keys.length"
                                class="mb-2 flex flex-wrap gap-x-3 text-xs"
                            >
                                <span class="text-muted-foreground"
                                    >Property</span
                                >
                                <button
                                    v-for="key in keys"
                                    :key="key"
                                    type="button"
                                    :class="
                                        property === key
                                            ? 'text-foreground underline underline-offset-4'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    @click="loadProps(event.value, key)"
                                >
                                    {{ key }}
                                </button>
                            </div>
                            <p
                                v-else-if="!loading"
                                class="pb-2 text-xs text-muted-foreground"
                            >
                                This event has no properties.
                            </p>
                            <ul
                                class="space-y-0.5"
                                :class="{ 'opacity-50': loading }"
                            >
                                <li
                                    v-for="row in propRows"
                                    :key="row.value"
                                    class="flex items-baseline gap-2 py-1 text-sm"
                                >
                                    <span
                                        class="max-w-[70%] min-w-0 truncate"
                                        >{{ row.value || '(empty)' }}</span
                                    >
                                    <span
                                        class="min-w-4 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="w-12 text-right"
                                        :style="{
                                            opacity:
                                                0.55 +
                                                0.45 * (row.events / maxProps),
                                        }"
                                        >{{ formatNumber(row.events) }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-2 py-6 text-sm text-muted-foreground">
                    No custom events yet. Send one with
                    <code class="rounded bg-muted px-1"
                        >ryvr.track('Signup', {{ '{' }} plan: 'pro'
                        {{ '}' }})</code
                    >
                    or add
                    <code class="rounded bg-muted px-1"
                        >data-ryvr-event="Signup"</code
                    >
                    to a link.
                </p>
            </div>

            <div>
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="text-sm">Goals</h2>
                    <span class="text-xs text-muted-foreground"
                        >Conversion</span
                    >
                </div>
                <ul v-if="goals.length" class="divide-y divide-border">
                    <li
                        v-for="goal in goals"
                        :key="goal.id"
                        class="flex items-baseline gap-3 py-2 text-sm"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="truncate">{{ goal.name }}</div>
                            <div class="truncate text-xs text-muted-foreground">
                                {{ goal.type === 'event' ? 'Event' : 'Page' }}
                                {{ goal.value }},
                                {{ formatNumber(goal.visitors) }} visitors
                            </div>
                        </div>
                        <span class="text-lg font-light">{{
                            formatPercent(goal.rate)
                        }}</span>
                    </li>
                </ul>
                <p v-else class="py-6 text-sm text-muted-foreground">
                    No goals yet.
                    <a
                        v-if="canManageGoals"
                        :href="`${settingsUrl}#goals`"
                        class="text-foreground underline underline-offset-4"
                        >Add a goal</a
                    >
                    to track conversion rates for a page or an event.
                </p>
            </div>
        </div>
    </section>
</template>
