<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrgNav from '@/components/ryvr/OrgNav.vue';

type Grant = {
    id?: number;
    project_id: string | null;
    group_id: string | null;
    role: string;
};
type Member = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    role: string;
    project_access: string;
    grants: Grant[];
    is_you: boolean;
};

const props = defineProps<{
    members: Member[];
    invitations: {
        id: string;
        email: string;
        role: string;
        project_access: string;
        expires_at: string;
    }[];
    projects: { id: string; name: string; domain: string }[];
    groups: { id: string; name: string }[];
    roles: { value: string; label: string }[];
    canManage: boolean;
    canManageOwners: boolean;
}>();

const page = usePage();
const invitationLink = computed(() => page.props.once?.invitationLink);

const ROLE_HELP: Record<string, string> = {
    owner: 'Everything, including deleting the organization and managing owners.',
    admin: 'Manage sites, members, exclusions and keys.',
    analyst: 'View analytics, create goals and use the API.',
    viewer: 'View dashboards and export data.',
    billing: 'Billing only. No access to analytics.',
};

const assignableRoles = computed(() =>
    props.roles.filter((r) => props.canManageOwners || r.value !== 'owner'),
);
const siteRoles = computed(() =>
    props.roles.filter((r) => ['admin', 'analyst', 'viewer'].includes(r.value)),
);

const invite = useForm({
    email: '',
    role: 'viewer',
    project_access: 'all',
    project_ids: [] as string[],
});

const editing = ref<number | null>(null);
const edit = useForm({
    role: 'viewer',
    project_access: 'all',
    grants: [] as Grant[],
});

function startEdit(member: Member) {
    editing.value = member.id;
    edit.role = member.role;
    edit.project_access = member.project_access;
    edit.grants = member.grants.map((g) => ({ ...g }));
    edit.clearErrors();
}

function saveEdit(member: Member) {
    edit.put(`/organization/members/${member.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

function grantLabel(grant: Grant) {
    if (grant.project_id)
        return (
            props.projects.find((p) => p.id === grant.project_id)?.name ??
            'Removed site'
        );
    return `${props.groups.find((g) => g.id === grant.group_id)?.name ?? 'Removed group'} (group)`;
}

const newGrant = ref('');
function addGrant() {
    if (!newGrant.value) return;
    const [kind, id] = newGrant.value.split(':');
    edit.grants.push({
        project_id: kind === 'p' ? id : null,
        group_id: kind === 'g' ? id : null,
        role: 'viewer',
    });
    newGrant.value = '';
}

function remove(member: Member) {
    const message = member.is_you
        ? 'Leave this organization?'
        : `Remove ${member.name}?`;
    if (window.confirm(message))
        router.delete(`/organization/members/${member.id}`, {
            preserveScroll: true,
        });
}

async function copy(text: string) {
    await navigator.clipboard.writeText(text);
}
</script>

<template>
    <Head title="Members" />
    <OrgNav />

    <section class="settings-section !border-t-0 !pt-0">
        <div>
            <h2 class="font-medium">Members</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Give clients access to only their own sites with "Selected
                sites".
            </p>
        </div>
        <ul class="divide-y divide-border rounded-lg border border-border">
            <li
                v-for="member in members"
                :key="member.id"
                class="px-4 py-3 text-sm"
            >
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <div class="min-w-0 flex-1">
                        <div>
                            {{ member.name }}
                            <span
                                v-if="member.is_you"
                                class="text-muted-foreground"
                                >(you)</span
                            >
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ member.email }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="capitalize">{{ member.role }}</div>
                        <div class="text-xs text-muted-foreground">
                            {{
                                member.project_access === 'all'
                                    ? 'All sites'
                                    : `${member.grants.length} selected`
                            }}
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <button
                            v-if="
                                canManage &&
                                editing !== member.id &&
                                (canManageOwners || member.role !== 'owner')
                            "
                            type="button"
                            class="text-muted-foreground hover:text-foreground"
                            @click="startEdit(member)"
                        >
                            Edit
                        </button>
                        <button
                            v-if="
                                member.is_you ||
                                (canManage &&
                                    (canManageOwners ||
                                        member.role !== 'owner'))
                            "
                            type="button"
                            class="text-destructive hover:underline"
                            @click="remove(member)"
                        >
                            {{ member.is_you ? 'Leave' : 'Remove' }}
                        </button>
                    </div>
                </div>

                <form
                    v-if="editing === member.id"
                    class="mt-4 space-y-4 rounded-lg bg-surface p-4"
                    @submit.prevent="saveEdit(member)"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label
                                class="field-label"
                                :for="`role-${member.id}`"
                                >Role</label
                            >
                            <select
                                :id="`role-${member.id}`"
                                v-model="edit.role"
                                class="field-input"
                            >
                                <option
                                    v-for="r in assignableRoles"
                                    :key="r.value"
                                    :value="r.value"
                                >
                                    {{ r.label }}
                                </option>
                            </select>
                            <p class="field-help">{{ ROLE_HELP[edit.role] }}</p>
                            <p v-if="edit.errors.role" class="field-error">
                                {{ edit.errors.role }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="field-label"
                                :for="`access-${member.id}`"
                                >Sites</label
                            >
                            <select
                                :id="`access-${member.id}`"
                                v-model="edit.project_access"
                                class="field-input"
                            >
                                <option value="all">All sites</option>
                                <option value="granted_only">
                                    Selected sites
                                </option>
                            </select>
                        </div>
                    </div>
                    <div v-if="edit.project_access === 'granted_only'">
                        <ul class="mb-3 space-y-2">
                            <li
                                v-for="(grant, i) in edit.grants"
                                :key="i"
                                class="flex items-center gap-3"
                            >
                                <span class="flex-1">{{
                                    grantLabel(grant)
                                }}</span>
                                <label
                                    class="sr-only"
                                    :for="`grant-${member.id}-${i}`"
                                    >Role for {{ grantLabel(grant) }}</label
                                >
                                <select
                                    :id="`grant-${member.id}-${i}`"
                                    v-model="grant.role"
                                    class="field-input w-32"
                                >
                                    <option
                                        v-for="r in siteRoles"
                                        :key="r.value"
                                        :value="r.value"
                                    >
                                        {{ r.label }}
                                    </option>
                                </select>
                                <button
                                    type="button"
                                    class="text-destructive"
                                    @click="edit.grants.splice(i, 1)"
                                >
                                    Remove
                                </button>
                            </li>
                            <li
                                v-if="!edit.grants.length"
                                class="text-muted-foreground"
                            >
                                No sites selected yet. This member will see
                                nothing.
                            </li>
                        </ul>
                        <div class="flex gap-2">
                            <label
                                class="sr-only"
                                :for="`add-grant-${member.id}`"
                                >Add a site or group</label
                            >
                            <select
                                :id="`add-grant-${member.id}`"
                                v-model="newGrant"
                                class="field-input"
                            >
                                <option value="">Add a site or group</option>
                                <optgroup v-if="groups.length" label="Groups">
                                    <option
                                        v-for="g in groups"
                                        :key="g.id"
                                        :value="`g:${g.id}`"
                                    >
                                        {{ g.name }}
                                    </option>
                                </optgroup>
                                <optgroup label="Sites">
                                    <option
                                        v-for="p in projects"
                                        :key="p.id"
                                        :value="`p:${p.id}`"
                                    >
                                        {{ p.name }}
                                    </option>
                                </optgroup>
                            </select>
                            <button
                                type="button"
                                class="btn-secondary shrink-0"
                                @click="addGrant"
                            >
                                Add
                            </button>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <button
                            type="submit"
                            class="btn-primary"
                            :disabled="edit.processing"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            class="btn-secondary"
                            @click="editing = null"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </li>
        </ul>
    </section>

    <section v-if="canManage" class="settings-section">
        <div>
            <h2 class="font-medium">Invite</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Invitations expire after 7 days.
            </p>
        </div>
        <div class="max-w-xl space-y-5">
            <div
                v-if="invitationLink"
                class="rounded-lg border border-border p-3 text-sm"
            >
                <p class="mb-2">
                    Share this link with the person you invited. It is shown
                    only once.
                </p>
                <div class="flex gap-2">
                    <input
                        :value="invitationLink"
                        readonly
                        class="field-input font-normal"
                        aria-label="Invitation link"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <button
                        type="button"
                        class="btn-secondary shrink-0"
                        @click="copy(invitationLink)"
                    >
                        Copy
                    </button>
                </div>
            </div>

            <form
                class="space-y-4"
                @submit.prevent="
                    invite.post('/organization/members/invite', {
                        preserveScroll: true,
                        onSuccess: () => invite.reset(),
                    })
                "
            >
                <div>
                    <label class="field-label" for="invite-email">Email</label>
                    <input
                        id="invite-email"
                        v-model="invite.email"
                        type="email"
                        class="field-input"
                        required
                        placeholder="name@example.com"
                    />
                    <p v-if="invite.errors.email" class="field-error">
                        {{ invite.errors.email }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="invite-role"
                            >Role</label
                        >
                        <select
                            id="invite-role"
                            v-model="invite.role"
                            class="field-input"
                        >
                            <option
                                v-for="r in invite.project_access === 'all'
                                    ? assignableRoles
                                    : siteRoles"
                                :key="r.value"
                                :value="r.value"
                            >
                                {{ r.label }}
                            </option>
                        </select>
                        <p class="field-help">{{ ROLE_HELP[invite.role] }}</p>
                        <p v-if="invite.errors.role" class="field-error">
                            {{ invite.errors.role }}
                        </p>
                    </div>
                    <div>
                        <label class="field-label" for="invite-access"
                            >Sites</label
                        >
                        <select
                            id="invite-access"
                            v-model="invite.project_access"
                            class="field-input"
                        >
                            <option value="all">All sites</option>
                            <option value="granted_only">Selected sites</option>
                        </select>
                    </div>
                </div>
                <fieldset
                    v-if="invite.project_access === 'granted_only'"
                    class="space-y-1"
                >
                    <legend class="field-label">Which sites</legend>
                    <label
                        v-for="p in projects"
                        :key="p.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="invite.project_ids"
                            type="checkbox"
                            :value="p.id"
                        />
                        {{ p.name }}
                        <span class="text-muted-foreground">{{
                            p.domain
                        }}</span>
                    </label>
                    <p v-if="invite.errors.project_ids" class="field-error">
                        Pick at least one site.
                    </p>
                </fieldset>
                <button
                    type="submit"
                    class="btn-primary"
                    :disabled="invite.processing"
                >
                    Send invitation
                </button>
            </form>

            <div v-if="invitations.length">
                <h3 class="mb-2 text-sm text-muted-foreground">Pending</h3>
                <ul
                    class="divide-y divide-border rounded-lg border border-border"
                >
                    <li
                        v-for="inv in invitations"
                        :key="inv.id"
                        class="flex items-center gap-3 px-3 py-2.5 text-sm"
                    >
                        <span class="flex-1 truncate">{{ inv.email }}</span>
                        <span class="text-muted-foreground capitalize">{{
                            inv.role
                        }}</span>
                        <button
                            type="button"
                            class="text-destructive hover:underline"
                            @click="
                                router.delete(
                                    `/organization/invitations/${inv.id}`,
                                    { preserveScroll: true },
                                )
                            "
                        >
                            Revoke
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
