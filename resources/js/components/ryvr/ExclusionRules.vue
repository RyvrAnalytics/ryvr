<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

export type Rule = { id: string; field: string; field_label: string; operator: string; operator_label: string; value: string; note: string | null; enabled: boolean };
type FieldOption = { value: string; label: string; operators: { value: string; label: string }[] };

const props = defineProps<{
    rules: Rule[];
    fields: FieldOption[];
    baseUrl: string;
    canManage: boolean;
    clientIp?: string;
}>();

const form = useForm({ field: 'ip', operator: 'equals', value: '', note: '' });

const operators = computed(() => props.fields.find((f) => f.value === form.field)?.operators ?? []);
watch(
    () => form.field,
    () => {
        if (!operators.value.some((o) => o.value === form.operator)) form.operator = operators.value[0]?.value ?? 'equals';
    },
);

const placeholder = computed(
    () =>
        ({
            ip: form.operator === 'cidr' ? '203.0.113.0/24' : '203.0.113.7',
            user_agent: 'MyUptimeChecker',
            path: '/admin',
            hostname: 'staging.example.com',
            referrer: 'internal-tool.example.com',
            country: 'CA',
        })[form.field] ?? '',
);

function submit() {
    form.post(props.baseUrl, { preserveScroll: true, onSuccess: () => form.reset('value', 'note') });
}

function prefillMyIp() {
    if (!props.clientIp) return;
    form.field = 'ip';
    form.operator = 'equals';
    form.value = props.clientIp;
    form.note = 'My network';
}

function toggle(rule: Rule) {
    router.patch(`${props.baseUrl}/${rule.id}`, { enabled: !rule.enabled }, { preserveScroll: true });
}

function remove(rule: Rule) {
    router.delete(`${props.baseUrl}/${rule.id}`, { preserveScroll: true });
}
</script>

<template>
    <div>
        <ul v-if="rules.length" class="mb-6 divide-y divide-border rounded-lg border border-border">
            <li v-for="rule in rules" :key="rule.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 text-sm" :class="{ 'opacity-50': !rule.enabled }">
                <span class="text-muted-foreground">{{ rule.field_label }} {{ rule.operator_label }}</span>
                <code class="rounded bg-muted px-1.5 py-0.5 text-[13px]">{{ rule.value }}</code>
                <span v-if="rule.note" class="text-muted-foreground">{{ rule.note }}</span>
                <span v-if="canManage" class="ml-auto flex gap-3">
                    <button type="button" class="text-muted-foreground hover:text-foreground" @click="toggle(rule)">{{ rule.enabled ? 'Pause' : 'Resume' }}</button>
                    <button type="button" class="text-destructive hover:underline" @click="remove(rule)">Remove</button>
                </span>
            </li>
        </ul>
        <p v-else class="mb-6 text-sm text-muted-foreground">No rules yet.</p>

        <form v-if="canManage" class="grid gap-3 sm:grid-cols-[10rem_11rem_1fr]" @submit.prevent="submit">
            <div>
                <label class="field-label" for="rule-field">Field</label>
                <select id="rule-field" v-model="form.field" class="field-input">
                    <option v-for="field in fields" :key="field.value" :value="field.value">{{ field.label }}</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="rule-operator">Condition</label>
                <select id="rule-operator" v-model="form.operator" class="field-input">
                    <option v-for="op in operators" :key="op.value" :value="op.value">{{ op.label }}</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="rule-value">Value</label>
                <input id="rule-value" v-model="form.value" class="field-input" :placeholder="placeholder" required maxlength="500" />
                <p v-if="form.errors.value || form.errors.operator" class="field-error">{{ form.errors.value || form.errors.operator }}</p>
            </div>
            <div class="sm:col-span-3 flex flex-wrap items-end gap-3">
                <div class="min-w-48 flex-1">
                    <label class="field-label" for="rule-note">Note <span class="text-muted-foreground">(optional)</span></label>
                    <input id="rule-note" v-model="form.note" class="field-input" placeholder="Office network, staging checks" maxlength="200" />
                </div>
                <button type="submit" class="btn-primary" :disabled="form.processing">Add rule</button>
                <button v-if="clientIp" type="button" class="btn-secondary" @click="prefillMyIp">Use my IP ({{ clientIp }})</button>
            </div>
        </form>
    </div>
</template>
