<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BuiltInFilters from '@/components/ryvr/BuiltInFilters.vue';
import ExclusionRules from '@/components/ryvr/ExclusionRules.vue';
import type { Rule } from '@/components/ryvr/ExclusionRules.vue';

type FieldOption = { value: string; label: string; operators: { value: string; label: string }[] };

const props = defineProps<{
    site: {
        id: string;
        name: string;
        domain: string;
        allowed_hostnames: string[];
        timezone: string;
        environment: string;
        hash_routing: boolean;
        groups: string[];
        ingest_key: string | null;
    };
    groups: { id: string; name: string }[];
    scriptUrl: string;
    timezones: string[];
    rules: Rule[];
    orgRules: Rule[];
    goals: { id: string; name: string; type: string; value: string }[];
    fields: FieldOption[];
    canManage: boolean;
    canManageGoals: boolean;
    canManageExclusions: boolean;
    clientIp: string;
}>();

const form = useForm({
    name: props.site.name,
    domain: props.site.domain,
    allowed_hostnames: props.site.allowed_hostnames.join('\n'),
    timezone: props.site.timezone,
    environment: props.site.environment,
    hash_routing: props.site.hash_routing,
    groups: [...props.site.groups],
});

function save() {
    form.transform((data) => ({
        ...data,
        allowed_hostnames: data.allowed_hostnames
            .split(/[\s,]+/)
            .map((h) => h.trim())
            .filter(Boolean),
    })).put(`/sites/${props.site.id}/settings`, { preserveScroll: true });
}

const snippet = computed(() => {
    const attrs = [`defer`, `src="${props.scriptUrl}"`, `data-key="${props.site.ingest_key}"`];
    if (props.site.hash_routing) attrs.push('data-hash');
    return `<script ${attrs.join(' ')}></` + `script>`;
});
const copied = ref(false);
async function copySnippet() {
    await navigator.clipboard.writeText(snippet.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

const goal = useForm({ name: '', type: 'pageview', value: '' });
function addGoal() {
    goal.post(`/sites/${props.site.id}/goals`, { preserveScroll: true, onSuccess: () => goal.reset() });
}

const confirmRotate = ref(false);
const deleteForm = useForm({ confirm: '' });
const showDelete = ref(false);
</script>

<template>
    <Head :title="`${site.name} settings`" />

    <div class="mb-6">
        <Link :href="`/sites/${site.id}`" class="text-sm text-muted-foreground hover:text-foreground">{{ site.name }}</Link>
        <h1 class="mt-1 text-3xl font-light tracking-tight">Settings</h1>
    </div>

    <section v-if="site.ingest_key" class="settings-section" aria-labelledby="tracking">
        <div>
            <h2 id="tracking" class="font-medium">Tracking code</h2>
            <p class="mt-1 text-sm text-muted-foreground">Paste this into the &lt;head&gt; of every page. No cookies, no consent banner needed for basic counting.</p>
        </div>
        <div class="min-w-0">
            <div class="relative rounded-lg border border-border bg-surface p-4 pr-20 text-[13px]">
                <code class="block break-all">{{ snippet }}</code>
                <button type="button" class="absolute right-3 top-3 rounded border border-border bg-background px-2 py-0.5 text-xs hover:bg-muted" @click="copySnippet">
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
            <details class="mt-3 text-sm">
                <summary class="cursor-pointer text-muted-foreground hover:text-foreground">Custom events, revenue and consent</summary>
                <div class="mt-3 space-y-3 text-muted-foreground">
                    <p>Track an action: <code class="rounded bg-muted px-1 text-foreground">ryvr.track('Signup', { plan: 'pro' })</code></p>
                    <p>With revenue: <code class="rounded bg-muted px-1 text-foreground">ryvr.track('Purchase', { sku: 'A1' }, { revenue: 49, currency: 'CAD' })</code></p>
                    <p>Without JavaScript: add <code class="rounded bg-muted px-1 text-foreground">data-ryvr-event="Download brochure"</code> to a link.</p>
                    <p>Outbound links and file downloads are tracked automatically. Turn that off with <code class="rounded bg-muted px-1 text-foreground">data-auto-links="false"</code>.</p>
                    <p>To wait for consent, add <code class="rounded bg-muted px-1 text-foreground">data-consent="required"</code> and call <code class="rounded bg-muted px-1 text-foreground">ryvr.consent(true)</code> once the visitor agrees.</p>
                </div>
            </details>
            <div v-if="canManage" class="mt-4 flex items-center gap-3 text-sm">
                <template v-if="!confirmRotate">
                    <button type="button" class="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline" @click="confirmRotate = true">Create a new key</button>
                </template>
                <template v-else>
                    <span>The current key stops working immediately.</span>
                    <button type="button" class="btn-danger" @click="router.post(`/sites/${site.id}/rotate-key`, {}, { preserveScroll: true, onFinish: () => (confirmRotate = false) })">Replace key</button>
                    <button type="button" class="text-muted-foreground" @click="confirmRotate = false">Cancel</button>
                </template>
            </div>
        </div>
    </section>

    <section class="settings-section" aria-labelledby="general">
        <div>
            <h2 id="general" class="font-medium">Site</h2>
            <p class="mt-1 text-sm text-muted-foreground">Hits from hostnames not listed here are dropped.</p>
        </div>
        <form class="max-w-xl space-y-4" @submit.prevent="save">
            <fieldset :disabled="!canManage" class="space-y-4">
                <div>
                    <label class="field-label" for="name">Name</label>
                    <input id="name" v-model="form.name" class="field-input" required maxlength="100" />
                    <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="field-label" for="domain">Domain</label>
                    <input id="domain" v-model="form.domain" class="field-input" required placeholder="example.com" />
                    <p class="field-help">The www. version is included automatically.</p>
                    <p v-if="form.errors.domain" class="field-error">{{ form.errors.domain }}</p>
                </div>
                <div>
                    <label class="field-label" for="hosts">Other hostnames</label>
                    <textarea id="hosts" v-model="form.allowed_hostnames" rows="3" class="field-input" placeholder="app.example.com&#10;*.example.dev" />
                    <p class="field-help">One per line. Use *.example.com for every subdomain.</p>
                    <p v-if="form.errors.allowed_hostnames" class="field-error">{{ form.errors.allowed_hostnames }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="timezone">Reporting timezone</label>
                        <select id="timezone" v-model="form.timezone" class="field-input">
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="environment">Environment</label>
                        <select id="environment" v-model="form.environment" class="field-input">
                            <option value="production">Production</option>
                            <option value="staging">Staging</option>
                            <option value="development">Development</option>
                        </select>
                    </div>
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.hash_routing" type="checkbox" class="mt-0.5" />
                    <span>Count #/routes as separate pages <span class="block text-xs text-muted-foreground">For single-page apps that use hash-based routing.</span></span>
                </label>
                <div v-if="groups.length">
                    <span class="field-label">Groups</span>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        <label v-for="group in groups" :key="group.id" class="flex items-center gap-1.5 text-sm">
                            <input v-model="form.groups" type="checkbox" :value="group.id" />
                            {{ group.name }}
                        </label>
                    </div>
                </div>
                <button v-if="canManage" type="submit" class="btn-primary" :disabled="form.processing">Save changes</button>
            </fieldset>
        </form>
    </section>

    <section id="goals" class="settings-section" aria-labelledby="goals-title">
        <div>
            <h2 id="goals-title" class="font-medium">Goals</h2>
            <p class="mt-1 text-sm text-muted-foreground">A goal counts the visitors who reached a page or triggered an event, and shows the conversion rate.</p>
        </div>
        <div class="max-w-xl">
            <ul v-if="goals.length" class="mb-5 divide-y divide-border rounded-lg border border-border">
                <li v-for="g in goals" :key="g.id" class="flex items-center gap-3 px-3 py-2.5 text-sm">
                    <span class="flex-1">{{ g.name }}</span>
                    <span class="text-muted-foreground">{{ g.type === 'event' ? 'Event' : 'Page' }}</span>
                    <code class="rounded bg-muted px-1.5 text-[13px]">{{ g.value }}</code>
                    <button v-if="canManageGoals" type="button" class="text-destructive hover:underline" @click="router.delete(`/sites/${site.id}/goals/${g.id}`, { preserveScroll: true })">Remove</button>
                </li>
            </ul>
            <form v-if="canManageGoals" class="grid gap-3 sm:grid-cols-[1fr_8rem_1fr_auto] sm:items-end" @submit.prevent="addGoal">
                <div>
                    <label class="field-label" for="goal-name">Name</label>
                    <input id="goal-name" v-model="goal.name" class="field-input" required placeholder="Signed up" />
                </div>
                <div>
                    <label class="field-label" for="goal-type">Type</label>
                    <select id="goal-type" v-model="goal.type" class="field-input">
                        <option value="pageview">Page</option>
                        <option value="event">Event</option>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="goal-value">{{ goal.type === 'event' ? 'Event name' : 'Page path' }}</label>
                    <input id="goal-value" v-model="goal.value" class="field-input" required :placeholder="goal.type === 'event' ? 'Signup' : '/thanks or /blog/*'" />
                </div>
                <button type="submit" class="btn-primary" :disabled="goal.processing">Add goal</button>
                <p v-if="goal.errors.value || goal.errors.name" class="field-error sm:col-span-4">{{ goal.errors.value || goal.errors.name }}</p>
            </form>
        </div>
    </section>

    <section id="exclusions" class="settings-section" aria-labelledby="exclusions-title">
        <div>
            <h2 id="exclusions-title" class="font-medium">Exclusions</h2>
            <p class="mt-1 text-sm text-muted-foreground">Traffic that matches a rule is not counted for this site. Rules apply to new hits only.</p>
        </div>
        <div class="max-w-2xl space-y-8">
            <ExclusionRules :rules="rules" :fields="fields" :base-url="`/sites/${site.id}/exclusions`" :can-manage="canManageExclusions" :client-ip="clientIp" />
            <div v-if="orgRules.length" class="text-sm">
                <p class="mb-2 text-muted-foreground">Also applied from your organization:</p>
                <ul class="space-y-1">
                    <li v-for="rule in orgRules" :key="rule.id" :class="{ 'opacity-50': !rule.enabled }">
                        {{ rule.field_label }} {{ rule.operator_label }} <code class="rounded bg-muted px-1">{{ rule.value }}</code>
                    </li>
                </ul>
                <Link href="/organization/exclusions" class="mt-2 inline-block text-muted-foreground underline underline-offset-4 hover:text-foreground">Edit organization rules</Link>
            </div>
            <BuiltInFilters />
        </div>
    </section>

    <section v-if="canManage" class="settings-section" aria-labelledby="danger">
        <div>
            <h2 id="danger" class="font-medium">Archive or delete</h2>
        </div>
        <div class="max-w-xl space-y-6 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-muted-foreground">Archiving stops tracking and hides the site. Data is kept and you can restore it later.</p>
                <button type="button" class="btn-secondary" @click="router.post(`/sites/${site.id}/archive`)">Archive site</button>
            </div>
            <div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-muted-foreground">Deleting removes the site and every event recorded for it. This cannot be undone.</p>
                    <button v-if="!showDelete" type="button" class="btn-danger" @click="showDelete = true">Delete site</button>
                </div>
                <form v-if="showDelete" class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="deleteForm.delete(`/sites/${site.id}`)">
                    <div class="flex-1">
                        <label class="field-label" for="confirm-delete">Type <strong>{{ site.domain }}</strong> to confirm</label>
                        <input id="confirm-delete" v-model="deleteForm.confirm" class="field-input" autocomplete="off" />
                        <p v-if="deleteForm.errors.confirm" class="field-error">{{ deleteForm.errors.confirm }}</p>
                    </div>
                    <button type="submit" class="btn-danger" :disabled="deleteForm.confirm !== site.domain || deleteForm.processing">Delete permanently</button>
                </form>
            </div>
        </div>
    </section>
</template>
