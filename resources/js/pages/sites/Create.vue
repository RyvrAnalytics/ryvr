<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ timezone: string; timezones: string[] }>();
const form = useForm({ name: '', domain: '', timezone: props.timezone });

function guessName() {
    if (form.name || !form.domain) return;
    const host = form.domain.replace(/^https?:\/\//, '').replace(/^www\./, '').split('/')[0];
    const base = host.split('.')[0] ?? '';
    form.name = base.charAt(0).toUpperCase() + base.slice(1);
}
</script>

<template>
    <Head title="Add site" />
    <div class="max-w-md">
        <Link href="/sites" class="text-sm text-muted-foreground hover:text-foreground">Sites</Link>
        <h1 class="mt-1 mb-6 text-3xl font-light tracking-tight">Add a site</h1>
        <form class="space-y-4" @submit.prevent="form.post('/sites')">
            <div>
                <label class="field-label" for="domain">Domain</label>
                <input id="domain" v-model="form.domain" v-focus class="field-input" placeholder="example.com" required @blur="guessName" />
                <p v-if="form.errors.domain" class="field-error">{{ form.errors.domain }}</p>
            </div>
            <div>
                <label class="field-label" for="name">Name</label>
                <input id="name" v-model="form.name" class="field-input" placeholder="Example" required maxlength="100" />
                <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
            </div>
            <div>
                <label class="field-label" for="timezone">Reporting timezone</label>
                <select id="timezone" v-model="form.timezone" class="field-input">
                    <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                </select>
                <p class="field-help">Days and hours on the dashboard follow this timezone.</p>
            </div>
            <button type="submit" class="btn-primary" :disabled="form.processing">Add site</button>
        </form>
    </div>
</template>
