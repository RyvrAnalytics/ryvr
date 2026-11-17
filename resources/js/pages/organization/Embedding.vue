<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrgNav from '@/components/ryvr/OrgNav.vue';

type EmbedKey = {
    id: string;
    public_id: string;
    name: string;
    preview: string;
    allowed_origins: string[];
    projects: string[] | null;
    widgets: string[];
    max_ttl_seconds: number;
    creator: string | null;
    last_used_at: string | null;
    created_at: string;
};

const props = defineProps<{
    keys: EmbedKey[];
    projects: { id: string; name: string }[];
    widgets: string[];
    ttlChoices: number[];
    embedUrl: string;
    mintUrl: string;
    scriptUrl: string;
}>();

const page = usePage();
const created = computed(() => page.props.once?.newEmbedKey);

const WIDGET_LABELS: Record<string, string> = {
    kpi: 'Totals: visitors, visits, pageviews, bounce rate',
    trend: 'Visitors over time',
    top_pages: 'Top pages',
    top_sources: 'Top sources',
    realtime: 'Visitors online now',
};

const originsText = ref('');
const form = useForm({
    name: '',
    allowed_origins: [] as string[],
    project_ids: [] as string[],
    widgets: [...props.widgets],
    max_ttl_seconds: 600,
});

function submit() {
    form.allowed_origins = originsText.value
        .split(/[\s,]+/)
        .map((o) => o.trim())
        .filter(Boolean);
    form.post('/organization/embedding', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            originsText.value = '';
        },
    });
}

const date = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleDateString('en', { dateStyle: 'medium' })
        : 'Never';
const siteNames = (ids: string[] | null) =>
    ids
        ? ids
              .map(
                  (id) =>
                      props.projects.find((p) => p.id === id)?.name ??
                      'Removed site',
              )
              .join(', ')
        : 'All sites';
const minutes = (s: number) => `${Math.round(s / 60)} min`;

const errorFor = (prefix: string) =>
    Object.entries(form.errors).find(([k]) => k.startsWith(prefix))?.[1];

const envSnippet = computed(() =>
    created.value
        ? `RYVR_EMBED_KEY_ID=${created.value.id}\nRYVR_EMBED_SECRET=${created.value.secret}`
        : '',
);

const htmlSnippet = computed(() => {
    const kid = created.value?.id ?? 'ek_...';
    const end = '</' + 'script>';

    return `<script src="${props.scriptUrl}">${end}
<div id="analytics"></div>
<script>
  RyvrEmbed.mount(document.getElementById('analytics'), {
    kid: '${kid}',
    // Your server checks its own session and returns a fresh embed token.
    fetchToken: () => fetch('/internal/ryvr-embed-token')
      .then((r) => r.json()).then((d) => d.token),
  });
${end}`;
});

function revoke(key: EmbedKey) {
    if (
        !window.confirm(
            `Revoke "${key.name}"? Every embed using it stops working now.`,
        )
    ) {
        return;
    }
    router.delete(`/organization/embedding/${key.id}`, {
        preserveScroll: true,
    });
}

function rotate(key: EmbedKey) {
    router.post(
        `/organization/embedding/${key.id}/rotate`,
        {},
        { preserveScroll: true },
    );
}

async function copy(text: string) {
    await navigator.clipboard.writeText(text);
}
</script>

<template>
    <Head title="Embedding" />
    <OrgNav />

    <section class="settings-section">
        <div>
            <h2 class="font-medium">Embed keys</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Show read-only charts in your own app. Your server signs a
                short-lived token for each viewer with an embed key. The secret
                stays on your server.
            </p>
        </div>
        <div class="space-y-6">
            <div
                v-if="created"
                class="rounded-lg border border-river p-4 text-sm"
            >
                <p class="mb-2 font-medium">
                    Copy the secret now. You will not see it again.
                </p>
                <p class="mb-2 text-muted-foreground">
                    Store both values as environment variables on your server.
                    Never put the secret in a browser, a mobile app or a public
                    repository.
                </p>
                <div class="flex gap-2">
                    <textarea
                        :value="envSnippet"
                        readonly
                        rows="2"
                        class="field-input font-mono text-[12px]"
                        aria-label="New embed key"
                        @focus="($event.target as HTMLTextAreaElement).select()"
                    />
                    <button
                        type="button"
                        class="btn-primary shrink-0 self-start"
                        @click="copy(envSnippet)"
                    >
                        Copy
                    </button>
                </div>
                <p class="mt-4 mb-2 text-muted-foreground">
                    Then add the widgets to a page of your app:
                </p>
                <pre
                    class="overflow-x-auto rounded-md bg-foreground p-3 text-[12px] text-background"
                    >{{ htmlSnippet }}</pre>
            </div>

            <ul
                v-if="keys.length"
                class="divide-y divide-border rounded-lg border border-border"
            >
                <li
                    v-for="key in keys"
                    :key="key.id"
                    class="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-3 text-sm"
                >
                    <div class="min-w-0 flex-1">
                        <div>
                            {{ key.name }}
                            <code class="text-muted-foreground">{{
                                key.public_id
                            }}</code>
                        </div>
                        <div class="mt-0.5 text-xs text-muted-foreground">
                            <code>{{ key.preview }}</code
                            >, {{ siteNames(key.projects) }}, tokens up to
                            {{ minutes(key.max_ttl_seconds) }}
                        </div>
                        <div
                            class="mt-0.5 text-xs break-all text-muted-foreground"
                        >
                            {{ key.allowed_origins.join(', ') }}
                        </div>
                        <div class="mt-0.5 text-xs text-muted-foreground">
                            {{ key.widgets.join(', ') }}
                        </div>
                    </div>
                    <div class="text-right text-xs text-muted-foreground">
                        <div>Last used {{ date(key.last_used_at) }}</div>
                        <div>Created {{ date(key.created_at) }}</div>
                    </div>
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="hover:underline"
                            @click="rotate(key)"
                        >
                            Rotate
                        </button>
                        <button
                            type="button"
                            class="text-destructive hover:underline"
                            @click="revoke(key)"
                        >
                            Revoke
                        </button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                No embed keys yet.
            </p>
            <p class="text-xs text-muted-foreground">
                Rotate creates a new key with the same settings. Deploy it, then
                revoke the old one. Both keep working in the meantime.
            </p>
        </div>
    </section>

    <section class="settings-section">
        <div>
            <h2 class="font-medium">Create an embed key</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                These are the limits. Each token can only narrow them.
            </p>
        </div>
        <form class="max-w-xl space-y-4" @submit.prevent="submit">
            <div>
                <label class="field-label" for="embed-name">Name</label>
                <input
                    id="embed-name"
                    v-model="form.name"
                    class="field-input"
                    required
                    maxlength="100"
                    placeholder="Customer portal"
                />
                <p v-if="form.errors.name" class="field-error">
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label class="field-label" for="embed-origins"
                    >Allowed origins</label
                >
                <textarea
                    id="embed-origins"
                    v-model="originsText"
                    class="field-input font-mono text-[13px]"
                    rows="3"
                    required
                    placeholder="https://app.example.com"
                />
                <p class="field-help">
                    One per line. Pages on these origins may show the charts.
                    Use https://*.example.com for subdomains, and
                    http://localhost:3000 while developing.
                </p>
                <p v-if="errorFor('allowed_origins')" class="field-error">
                    {{ errorFor('allowed_origins') }}
                </p>
            </div>
            <fieldset>
                <legend class="field-label">Widgets</legend>
                <label
                    v-for="widget in widgets"
                    :key="widget"
                    class="mb-1 flex items-start gap-2 text-sm"
                >
                    <input
                        v-model="form.widgets"
                        type="checkbox"
                        :value="widget"
                        class="mt-1"
                    />
                    <span
                        ><code>{{ widget }}</code>
                        <span class="block text-xs text-muted-foreground">{{
                            WIDGET_LABELS[widget]
                        }}</span></span
                    >
                </label>
                <p v-if="errorFor('widgets')" class="field-error">
                    {{ errorFor('widgets') }}
                </p>
            </fieldset>
            <fieldset>
                <legend class="field-label">Sites</legend>
                <p class="field-help mb-2">
                    Leave all unchecked for every site you can see.
                </p>
                <div class="grid gap-1 sm:grid-cols-2">
                    <label
                        v-for="p in projects"
                        :key="p.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="form.project_ids"
                            type="checkbox"
                            :value="p.id"
                        />
                        {{ p.name }}
                    </label>
                </div>
            </fieldset>
            <div>
                <label class="field-label" for="embed-ttl"
                    >Longest token lifetime</label
                >
                <select
                    id="embed-ttl"
                    v-model="form.max_ttl_seconds"
                    class="field-input w-48"
                >
                    <option v-for="ttl in ttlChoices" :key="ttl" :value="ttl">
                        {{ minutes(ttl) }}
                    </option>
                </select>
                <p class="field-help">
                    Short lifetimes limit the damage of a leaked token. The
                    library refreshes tokens before they expire.
                </p>
            </div>
            <button
                type="submit"
                class="btn-primary"
                :disabled="form.processing"
            >
                Create embed key
            </button>
        </form>
    </section>

    <section class="settings-section">
        <div>
            <h2 class="font-medium">Endpoints</h2>
        </div>
        <dl class="max-w-xl space-y-3 text-sm">
            <div>
                <dt class="text-muted-foreground">Embed library</dt>
                <dd>
                    <code>{{ scriptUrl }}</code>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Embed API and iframe</dt>
                <dd>
                    <code>{{ embedUrl }}/&lt;key id&gt;/dashboard</code>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    Mint tokens without a JWT library (server only, needs an API
                    key with embed:mint)
                </dt>
                <dd>
                    <code>POST {{ mintUrl }}</code>
                </dd>
            </div>
        </dl>
    </section>
</template>
