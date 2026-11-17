<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ timezones: string[]; additional: boolean }>();

const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const form = useForm({
    organization: '',
    timezone: props.timezones.includes(browserZone) ? browserZone : 'UTC',
    site_name: '',
    domain: '',
});

defineOptions({
    layout: {
        title: 'Set up RYVR',
        description: 'Name your organization and add your first site.',
    },
});
</script>

<template>
    <Head :title="additional ? 'New organization' : 'Get started'" />
    <form class="space-y-5" @submit.prevent="form.post('/start')">
        <div>
            <label class="field-label" for="organization"
                >Organization name</label
            >
            <input
                id="organization"
                v-model="form.organization"
                v-focus
                class="field-input"
                placeholder="Your company or agency"
                required
                maxlength="100"
            />
            <p class="field-help">
                Sites, members and billing belong to an organization. You can
                create more later.
            </p>
            <p v-if="form.errors.organization" class="field-error">
                {{ form.errors.organization }}
            </p>
        </div>
        <div>
            <label class="field-label" for="timezone">Timezone</label>
            <select id="timezone" v-model="form.timezone" class="field-input">
                <option v-for="tz in timezones" :key="tz" :value="tz">
                    {{ tz }}
                </option>
            </select>
        </div>
        <fieldset class="space-y-3 border-t border-border pt-5">
            <legend class="text-sm text-muted-foreground">
                First site (you can skip this)
            </legend>
            <div>
                <label class="field-label" for="domain">Domain</label>
                <input
                    id="domain"
                    v-model="form.domain"
                    class="field-input"
                    placeholder="example.com"
                />
                <p v-if="form.errors.domain" class="field-error">
                    {{ form.errors.domain }}
                </p>
            </div>
            <div>
                <label class="field-label" for="site_name">Site name</label>
                <input
                    id="site_name"
                    v-model="form.site_name"
                    class="field-input"
                    placeholder="Example"
                    :required="!!form.domain"
                />
                <p v-if="form.errors.site_name" class="field-error">
                    {{ form.errors.site_name }}
                </p>
            </div>
        </fieldset>
        <div class="flex items-center gap-4">
            <button
                type="submit"
                class="btn-primary w-full"
                :disabled="form.processing"
            >
                {{ additional ? 'Create organization' : 'Continue' }}
            </button>
        </div>
        <Link
            v-if="additional"
            href="/sites"
            class="block text-center text-sm text-muted-foreground hover:text-foreground"
            >Cancel</Link
        >
    </form>
</template>
