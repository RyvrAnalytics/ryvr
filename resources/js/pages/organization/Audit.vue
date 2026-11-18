<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import OrgNav from '@/components/ryvr/OrgNav.vue';

type Log = {
    id: number;
    label: string;
    actor: { name: string; email: string | null; type: string };
    subject: string | null;
    metadata: Record<string, unknown> | null;
    ip: string | null;
    at: string;
};
type Filters = { action: string; actor: string; from: string; to: string };
type Option = { value: string; label: string };

const props = defineProps<{
    logs: {
        data: Log[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Filters;
    actions: Option[];
    actors: Option[];
    timezone: string;
}>();

const form = useForm({ ...props.filters });
const filtered = computed(() => Object.values(props.filters).some(Boolean));
const time = (iso: string) =>
    new Date(iso).toLocaleString('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: props.timezone,
    });
const formatValue = (value: unknown): string => {
    if (value === null) return 'None';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};
const details = (meta: Record<string, unknown> | null) =>
    Object.entries(meta ?? {}).map(([key, value]) => ({
        key: key.replace(/_/g, ' '),
        value: formatValue(value),
    }));
function apply() {
    form.get('/organization/audit', {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Audit log" />
    <OrgNav />

    <div class="mb-5">
        <h1 class="text-2xl font-light tracking-tight">Audit log</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Organization activity. Times are shown in {{ timezone }}.
        </p>
    </div>

    <form
        class="mb-5 grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_1fr_auto]"
        @submit.prevent="apply"
    >
        <div>
            <label for="audit-action" class="field-label">Action</label>
            <select id="audit-action" v-model="form.action" class="field-input">
                <option value="">All actions</option>
                <option
                    v-for="action in actions"
                    :key="action.value"
                    :value="action.value"
                >
                    {{ action.label }}
                </option>
            </select>
        </div>
        <div>
            <label for="audit-actor" class="field-label">Actor</label>
            <select id="audit-actor" v-model="form.actor" class="field-input">
                <option value="">All actors</option>
                <option
                    v-for="actor in actors"
                    :key="actor.value"
                    :value="actor.value"
                >
                    {{ actor.label }}
                </option>
            </select>
        </div>
        <div>
            <label for="audit-from" class="field-label">From</label>
            <input
                id="audit-from"
                v-model="form.from"
                type="date"
                class="field-input"
            />
            <p v-if="form.errors.from" class="field-error">
                {{ form.errors.from }}
            </p>
        </div>
        <div>
            <label for="audit-to" class="field-label">Through</label>
            <input
                id="audit-to"
                v-model="form.to"
                type="date"
                :min="form.from || undefined"
                class="field-input"
            />
            <p v-if="form.errors.to" class="field-error">
                {{ form.errors.to }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <button
                type="submit"
                class="btn-primary"
                :disabled="form.processing"
            >
                Apply
            </button>
            <Link
                v-if="filtered"
                href="/organization/audit"
                class="text-sm text-muted-foreground underline"
                >Clear</Link
            >
        </div>
    </form>

    <div class="overflow-x-auto rounded-xl border border-border">
        <table v-if="logs.data.length" class="w-full min-w-[800px] text-sm">
            <thead class="bg-surface text-left text-xs text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-2.5 font-normal">When</th>
                    <th scope="col" class="px-4 py-2.5 font-normal">Who</th>
                    <th scope="col" class="px-4 py-2.5 font-normal">What</th>
                    <th scope="col" class="px-4 py-2.5 font-normal">Subject</th>
                    <th scope="col" class="px-4 py-2.5 font-normal">Details</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="log in logs.data"
                    :key="log.id"
                    class="border-t border-border align-top"
                >
                    <td
                        class="px-4 py-2.5 whitespace-nowrap text-muted-foreground"
                    >
                        <time :datetime="log.at">{{ time(log.at) }}</time>
                    </td>
                    <td class="px-4 py-2.5 break-words">
                        <div>{{ log.actor.name }}</div>
                        <div
                            v-if="log.actor.email"
                            class="text-xs text-muted-foreground"
                        >
                            {{ log.actor.email }}
                        </div>
                        <div
                            v-if="log.actor.type === 'api_key'"
                            class="text-xs text-muted-foreground"
                        >
                            API key
                        </div>
                    </td>
                    <td class="px-4 py-2.5">{{ log.label }}</td>
                    <td class="max-w-60 px-4 py-2.5 break-words">
                        {{ log.subject ?? 'None' }}
                    </td>
                    <td class="max-w-72 px-4 py-2.5 text-muted-foreground">
                        <details v-if="log.metadata || log.ip">
                            <summary class="cursor-pointer">
                                {{
                                    details(log.metadata).length
                                        ? `${details(log.metadata).length} ${details(log.metadata).length === 1 ? 'field' : 'fields'}`
                                        : 'IP address'
                                }}
                            </summary>
                            <dl class="mt-2 space-y-1 text-xs break-words">
                                <div
                                    v-for="detail in details(log.metadata)"
                                    :key="detail.key"
                                >
                                    <dt class="inline font-medium">
                                        {{ detail.key }}:
                                    </dt>
                                    <dd class="inline whitespace-pre-wrap">
                                        {{ detail.value }}
                                    </dd>
                                </div>
                                <div v-if="log.ip">
                                    <dt class="inline font-medium">IP:</dt>
                                    <dd class="inline">{{ log.ip }}</dd>
                                </div>
                            </dl>
                        </details>
                        <span v-else>None</span>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-else class="px-4 py-10 text-center text-sm text-muted-foreground">
            {{
                logs.total
                    ? 'No audit entries on this page. Choose another page below.'
                    : filtered
                      ? 'No audit entries match these filters.'
                      : 'Nothing recorded yet. Organization activity will appear here.'
            }}
        </p>
    </div>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
        <p class="text-muted-foreground" aria-live="polite">
            {{
                logs.from !== null
                    ? `${logs.from} to ${logs.to} of ${logs.total} entries`
                    : `${logs.total} entries`
            }}
        </p>
        <nav
            v-if="logs.total > logs.data.length"
            class="flex flex-wrap gap-1"
            aria-label="Audit log pages"
        >
            <template v-for="link in logs.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded px-2.5 py-1"
                    :aria-current="link.active ? 'page' : undefined"
                    :class="
                        link.active
                            ? 'bg-foreground text-background'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                >
                    <span v-html="link.label" />
                </Link>
                <span
                    v-else
                    class="px-2.5 py-1 text-muted-foreground opacity-50"
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>
