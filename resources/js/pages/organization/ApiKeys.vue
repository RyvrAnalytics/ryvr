<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import OrgNav from '@/components/ryvr/OrgNav.vue';

type Key = {
    id: string;
    name: string;
    type: string;
    owner: string | null;
    preview: string;
    scopes: string[];
    projects: string[] | null;
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
    mine: boolean;
};

const props = defineProps<{
    keys: Key[];
    projects: { id: string; name: string }[];
    scopes: string[];
    canUseApi: boolean;
    canManage: boolean;
    mcpUrl: string;
    apiUrl: string;
}>();

const page = usePage();
const newToken = computed(() => page.props.once?.newToken);

const SCOPE_HELP: Record<string, string> = {
    'analytics:read': 'Read reports: totals, trends, top pages and sources.',
    'analytics:query_raw':
        'Run flexible queries over events. Grant only to tools you trust.',
    'projects:write': 'Change site settings and goals.',
    'embed:mint':
        'Create short-lived embed tokens on your server. Never use this key in a browser.',
};

const form = useForm({
    name: '',
    type: 'pat',
    scopes: ['analytics:read'],
    project_ids: [] as string[],
    expires_in_days: 90 as number | null,
});

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

const mcpConfig = computed(() =>
    JSON.stringify(
        {
            mcpServers: {
                ryvr: {
                    type: 'http',
                    url: props.mcpUrl,
                    headers: {
                        Authorization: `Bearer ${newToken.value ?? 'ryvr_pat_...'}`,
                    },
                },
            },
        },
        null,
        2,
    ),
);

async function copy(text: string) {
    await navigator.clipboard.writeText(text);
}
</script>

<template>
    <Head title="API and MCP" />
    <OrgNav />

    <section class="settings-section">
        <div>
            <h2 class="font-medium">Keys</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Use a key with the REST API or connect an AI assistant over MCP.
                A key never sees more than its owner can.
            </p>
        </div>
        <div class="space-y-6">
            <div
                v-if="newToken"
                class="rounded-lg border border-river p-4 text-sm"
            >
                <p class="mb-2 font-medium">
                    Copy your key now. You will not see it again.
                </p>
                <div class="flex gap-2">
                    <input
                        :value="newToken"
                        readonly
                        class="field-input"
                        aria-label="New API key"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <button
                        type="button"
                        class="btn-primary shrink-0"
                        @click="copy(newToken)"
                    >
                        Copy
                    </button>
                </div>
                <p class="mt-4 mb-2 text-muted-foreground">
                    To connect an MCP client, add this to its configuration:
                </p>
                <pre
                    class="overflow-x-auto rounded-md bg-foreground p-3 text-[12px] text-background"
                    >{{ mcpConfig }}</pre>
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
                            <span class="text-muted-foreground">{{
                                key.type === 'org'
                                    ? 'organization key'
                                    : `personal, ${key.owner}`
                            }}</span>
                        </div>
                        <div class="mt-0.5 text-xs text-muted-foreground">
                            <code>{{ key.preview }}</code
                            >, {{ key.scopes.join(', ') }},
                            {{ siteNames(key.projects) }}
                        </div>
                    </div>
                    <div class="text-right text-xs text-muted-foreground">
                        <div>Last used {{ date(key.last_used_at) }}</div>
                        <div>Expires {{ date(key.expires_at) }}</div>
                    </div>
                    <button
                        v-if="key.mine || canManage"
                        type="button"
                        class="text-destructive hover:underline"
                        @click="
                            router.delete(`/organization/api-keys/${key.id}`, {
                                preserveScroll: true,
                            })
                        "
                    >
                        Revoke
                    </button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">No keys yet.</p>
        </div>
    </section>

    <section v-if="canUseApi" class="settings-section">
        <div>
            <h2 class="font-medium">Create a key</h2>
        </div>
        <form
            class="max-w-xl space-y-4"
            @submit.prevent="
                form.post('/organization/api-keys', {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                })
            "
        >
            <div>
                <label class="field-label" for="key-name">Name</label>
                <input
                    id="key-name"
                    v-model="form.name"
                    class="field-input"
                    required
                    maxlength="100"
                    placeholder="Weekly report script"
                />
            </div>
            <div v-if="canManage">
                <span class="field-label">Belongs to</span>
                <div class="flex gap-4 text-sm">
                    <label class="flex items-center gap-1.5"
                        ><input v-model="form.type" type="radio" value="pat" />
                        Me</label
                    >
                    <label class="flex items-center gap-1.5"
                        ><input v-model="form.type" type="radio" value="org" />
                        The organization</label
                    >
                </div>
                <p class="field-help">
                    Organization keys keep working when people leave. Use them
                    for servers and shared tools.
                </p>
            </div>
            <fieldset>
                <legend class="field-label">Permissions</legend>
                <label
                    v-for="scope in scopes"
                    :key="scope"
                    class="mb-1 flex items-start gap-2 text-sm"
                >
                    <input
                        v-model="form.scopes"
                        type="checkbox"
                        :value="scope"
                        class="mt-1"
                    />
                    <span
                        ><code>{{ scope }}</code>
                        <span class="block text-xs text-muted-foreground">{{
                            SCOPE_HELP[scope]
                        }}</span></span
                    >
                </label>
                <p v-if="form.errors.scopes" class="field-error">
                    {{ form.errors.scopes }}
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
                <label class="field-label" for="key-expiry">Expires</label>
                <select
                    id="key-expiry"
                    v-model="form.expires_in_days"
                    class="field-input w-48"
                >
                    <option :value="30">In 30 days</option>
                    <option :value="90">In 90 days</option>
                    <option :value="365">In a year</option>
                    <option :value="null">Never</option>
                </select>
            </div>
            <button
                type="submit"
                class="btn-primary"
                :disabled="form.processing"
            >
                Create key
            </button>
        </form>
    </section>

    <section class="settings-section">
        <div>
            <h2 class="font-medium">Endpoints</h2>
        </div>
        <dl class="max-w-xl space-y-3 text-sm">
            <div>
                <dt class="text-muted-foreground">REST API</dt>
                <dd>
                    <code>{{ apiUrl }}</code>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    MCP server (streamable HTTP)
                </dt>
                <dd>
                    <code>{{ mcpUrl }}</code>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Authentication</dt>
                <dd><code>Authorization: Bearer &lt;key&gt;</code></dd>
            </div>
        </dl>
    </section>
</template>
