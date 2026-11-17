<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import OrgNav from '@/components/ryvr/OrgNav.vue';

const props = defineProps<{
    settings: {
        name: string;
        timezone: string;
        currency: string;
        mcp_enabled: boolean;
    };
    timezones: string[];
    groups: { id: string; name: string; projects_count: number }[];
    archived: {
        id: string;
        name: string;
        domain: string;
        archived_at: string;
    }[];
    canManage: boolean;
    canManageProjects: boolean;
}>();

const page = usePage();
const form = useForm({ ...props.settings });
const group = useForm({ name: '' });
const showDelete = ref(false);
const deleteForm = useForm({ confirm: '' });
</script>

<template>
    <Head title="Organization" />
    <OrgNav />

    <section class="settings-section">
        <div>
            <h2 class="font-medium">General</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                The timezone and currency used when sites are added together.
            </p>
        </div>
        <form
            class="max-w-xl space-y-4"
            @submit.prevent="
                form.put('/organization', { preserveScroll: true })
            "
        >
            <fieldset :disabled="!canManage" class="space-y-4">
                <div>
                    <label class="field-label" for="org-name">Name</label>
                    <input
                        id="org-name"
                        v-model="form.name"
                        class="field-input"
                        required
                        maxlength="100"
                    />
                    <p v-if="form.errors.name" class="field-error">
                        {{ form.errors.name }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                    <div>
                        <label class="field-label" for="org-tz">Timezone</label>
                        <select
                            id="org-tz"
                            v-model="form.timezone"
                            class="field-input"
                        >
                            <option
                                v-for="tz in timezones"
                                :key="tz"
                                :value="tz"
                            >
                                {{ tz }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="org-currency"
                            >Currency</label
                        >
                        <input
                            id="org-currency"
                            v-model="form.currency"
                            class="field-input uppercase"
                            maxlength="3"
                            required
                        />
                        <p v-if="form.errors.currency" class="field-error">
                            {{ form.errors.currency }}
                        </p>
                    </div>
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <input
                        v-model="form.mcp_enabled"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    <span
                        >Allow AI assistants to read analytics over MCP
                        <span class="block text-xs text-muted-foreground"
                            >Members still need a key, and assistants only see
                            the sites that key allows.</span
                        ></span
                    >
                </label>
                <button
                    v-if="canManage"
                    type="submit"
                    class="btn-primary"
                    :disabled="form.processing"
                >
                    Save changes
                </button>
            </fieldset>
        </form>
    </section>

    <section class="settings-section">
        <div>
            <h2 class="font-medium">Groups</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Group sites by client, brand or team. Groups filter the Sites
                list and can be shared with members.
            </p>
        </div>
        <div class="max-w-xl">
            <ul
                v-if="groups.length"
                class="mb-4 divide-y divide-border rounded-lg border border-border"
            >
                <li
                    v-for="g in groups"
                    :key="g.id"
                    class="flex items-center gap-3 px-3 py-2.5 text-sm"
                >
                    <span class="flex-1">{{ g.name }}</span>
                    <span class="text-muted-foreground"
                        >{{ g.projects_count }}
                        {{ g.projects_count === 1 ? 'site' : 'sites' }}</span
                    >
                    <button
                        v-if="canManageProjects"
                        type="button"
                        class="text-destructive hover:underline"
                        @click="
                            router.delete(`/organization/groups/${g.id}`, {
                                preserveScroll: true,
                            })
                        "
                    >
                        Delete
                    </button>
                </li>
            </ul>
            <form
                v-if="canManageProjects"
                class="flex gap-2"
                @submit.prevent="
                    group.post('/organization/groups', {
                        preserveScroll: true,
                        onSuccess: () => group.reset(),
                    })
                "
            >
                <label for="group-name" class="sr-only">Group name</label>
                <input
                    id="group-name"
                    v-model="group.name"
                    class="field-input"
                    placeholder="Client name"
                    required
                    maxlength="60"
                />
                <button
                    type="submit"
                    class="btn-secondary shrink-0"
                    :disabled="group.processing"
                >
                    Add group
                </button>
            </form>
            <p v-if="group.errors.name" class="field-error">
                {{ group.errors.name }}
            </p>
        </div>
    </section>

    <section v-if="archived.length" class="settings-section">
        <div>
            <h2 class="font-medium">Archived sites</h2>
        </div>
        <ul
            class="max-w-xl divide-y divide-border rounded-lg border border-border"
        >
            <li
                v-for="site in archived"
                :key="site.id"
                class="flex items-center gap-3 px-3 py-2.5 text-sm"
            >
                <span class="flex-1"
                    >{{ site.name }}
                    <span class="text-muted-foreground">{{
                        site.domain
                    }}</span></span
                >
                <button
                    v-if="canManageProjects"
                    type="button"
                    class="text-muted-foreground hover:text-foreground"
                    @click="
                        router.post(
                            `/organization/projects/${site.id}/restore`,
                            {},
                            { preserveScroll: true },
                        )
                    "
                >
                    Restore
                </button>
            </li>
        </ul>
    </section>

    <section v-if="canManage" class="settings-section">
        <div>
            <h2 class="font-medium">Delete organization</h2>
        </div>
        <div class="max-w-xl text-sm">
            <p class="text-muted-foreground">
                Deletes every site, member, key and event in
                {{ page.props.organization?.name }}. This cannot be undone.
            </p>
            <button
                v-if="!showDelete"
                type="button"
                class="btn-danger mt-3"
                @click="showDelete = true"
            >
                Delete organization
            </button>
            <form
                v-else
                class="mt-3 flex flex-wrap items-end gap-3"
                @submit.prevent="deleteForm.delete('/organization')"
            >
                <div class="flex-1">
                    <label class="field-label" for="confirm-org"
                        >Type <strong>{{ settings.name }}</strong> to
                        confirm</label
                    >
                    <input
                        id="confirm-org"
                        v-model="deleteForm.confirm"
                        class="field-input"
                        autocomplete="off"
                    />
                    <p v-if="deleteForm.errors.confirm" class="field-error">
                        {{ deleteForm.errors.confirm }}
                    </p>
                </div>
                <button
                    type="submit"
                    class="btn-danger"
                    :disabled="deleteForm.confirm !== settings.name"
                >
                    Delete permanently
                </button>
            </form>
        </div>
    </section>
</template>
